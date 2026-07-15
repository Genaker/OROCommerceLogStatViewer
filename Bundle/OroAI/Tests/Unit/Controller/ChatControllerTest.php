<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Controller;

use Genaker\Bundle\OroAI\Agent\ChatProgressStore;
use Genaker\Bundle\OroAI\Agent\OroAiAgent;
use Genaker\Bundle\OroAI\Agent\HarnessInterface;
use Genaker\Bundle\OroAI\Controller\ChatController;
use Genaker\Bundle\OroAI\Core\Model\AgentResult;
use Genaker\Bundle\OroAI\Core\Model\ChatMessage;
use Genaker\Bundle\OroAI\Service\OroAiConfig;
use Oro\Bundle\DashboardBundle\Model\WidgetConfigs;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

final class ChatControllerTest extends TestCase
{
    private OroAiAgent&MockObject $agent;
    private HarnessInterface&MockObject $harness;
    private OroAiConfig&MockObject $config;
    private Environment&MockObject $twig;
    private ChatProgressStore&MockObject $progressStore;
    private WidgetConfigs&MockObject $widgetConfigs;
    private ChatController $controller;

    protected function setUp(): void
    {
        // Role enum is co-defined in ChatMessage.php; ensure it's loaded before any test
        // that may invoke parseHistory (which calls Role::tryFrom).
        class_exists(ChatMessage::class, true);

        $this->agent   = $this->createMock(OroAiAgent::class);
        $this->harness = $this->createMock(HarnessInterface::class);
        $this->config  = $this->createMock(OroAiConfig::class);
        $this->twig    = $this->createMock(Environment::class);
        $this->progressStore = $this->createMock(ChatProgressStore::class);
        $this->widgetConfigs = $this->createMock(WidgetConfigs::class);
        $this->config->method('isHarnessEnabled')->willReturn(false);
        $this->controller = new ChatController(
            $this->agent,
            $this->harness,
            $this->config,
            $this->twig,
            $this->progressStore,
            $this->widgetConfigs,
        );
    }

    public function testMessageActionReturnsNotConfiguredWhenNoApiKey(): void
    {
        $this->config->method('isConfigured')->willReturn(false);

        $request = new Request([], [], [], [], [], [], json_encode(['message' => 'hello']));
        $response = $this->controller->messageAction($request);

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertTrue($data['not_configured']);
        self::assertStringContainsString('not configured', $data['error']);
        self::assertStringContainsString('API key', $data['error']);
    }

    public function testMessageActionReturnsErrorOnEmptyMessage(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $request = new Request([], [], [], [], [], [], json_encode(['message' => '']));
        $response = $this->controller->messageAction($request);

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertStringContainsString('required', $data['error']);
    }

    public function testMessageActionReturnsAgentReply(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $this->agent->method('run')
            ->with('Where are customer users?', [])
            ->willReturn(new AgentResult(
                'Customer users are at /admin/customer/user/',
                [['tool' => 'entity_url', 'args' => '{}', 'result' => '/admin/customer/user/']],
                ['/admin/customer/user/'],
            ));

        $request = new Request([], [], [], [], [], [], json_encode([
            'message' => 'Where are customer users?',
            'history' => [],
        ]));
        $response = $this->controller->messageAction($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertStringContainsString('customer/user', $data['reply']);
        self::assertCount(1, $data['tool_trace']);
        self::assertContains('/admin/customer/user/', $data['links']);
    }

    public function testMessageActionIncludesUsageInResponse(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $this->agent->method('run')->willReturn(new AgentResult(
            'ok',
            [],
            [],
            ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120],
        ));

        $request = new Request([], [], [], [], [], [], json_encode(['message' => 'test', 'history' => []]));
        $response = $this->controller->messageAction($request);

        $data = json_decode($response->getContent(), true);
        self::assertSame(['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120], $data['usage']);
    }

    public function testMessageActionWithRequestIdWritesProgressAndClearsItAfterwards(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->agent->method('run')->willReturn(new AgentResult('ok'));

        $this->progressStore->expects(self::never())->method('addStep');
        $this->progressStore->expects(self::once())->method('clear')->with('req-123');

        $request = new Request([], [], [], [], [], [], json_encode([
            'message' => 'test',
            'history' => [],
            'request_id' => 'req-123',
        ]));

        $this->controller->messageAction($request);
    }

    public function testMessageActionForwardsOnProgressCallbackToAgentWhenRequestIdGiven(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $capturedCallback = null;
        $this->agent->method('run')
            ->willReturnCallback(function (string $msg, array $history, ?callable $onProgress) use (&$capturedCallback): AgentResult {
                $capturedCallback = $onProgress;

                return new AgentResult('ok');
            });

        $request = new Request([], [], [], [], [], [], json_encode([
            'message' => 'test',
            'history' => [],
            'request_id' => 'req-123',
        ]));

        $this->controller->messageAction($request);

        self::assertIsCallable($capturedCallback);

        $this->progressStore->expects(self::once())
            ->method('addStep')
            ->with('req-123', ['type' => 'tool_call', 'tool' => 'sql_query']);

        $capturedCallback(['type' => 'tool_call', 'tool' => 'sql_query']);
    }

    public function testMessageActionWithoutRequestIdPassesNullProgressCallback(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $capturedCallback = 'not-set';
        $this->agent->method('run')
            ->willReturnCallback(function (string $msg, array $history, ?callable $onProgress) use (&$capturedCallback): AgentResult {
                $capturedCallback = $onProgress;

                return new AgentResult('ok');
            });

        $request = new Request([], [], [], [], [], [], json_encode(['message' => 'test', 'history' => []]));
        $this->controller->messageAction($request);

        self::assertNull($capturedCallback);
    }

    public function testProgressActionReturnsStepsFromStore(): void
    {
        $this->progressStore->method('getSteps')
            ->with('req-123')
            ->willReturn([['type' => 'tool_call', 'tool' => 'sql_query']]);

        $request = new Request(['request_id' => 'req-123']);
        $response = $this->controller->progressAction($request);

        $data = json_decode($response->getContent(), true);
        self::assertSame([['type' => 'tool_call', 'tool' => 'sql_query']], $data['steps']);
    }

    public function testProgressActionReturnsEmptyStepsForMissingRequestId(): void
    {
        $request = new Request();
        $response = $this->controller->progressAction($request);

        $data = json_decode($response->getContent(), true);
        self::assertSame([], $data['steps']);
    }

    public function testMessageActionHandlesAgentException(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $this->agent->method('run')
            ->willThrowException(new \RuntimeException('LLM API timeout'));

        $request = new Request([], [], [], [], [], [], json_encode(['message' => 'test']));
        $response = $this->controller->messageAction($request);

        self::assertSame(500, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertStringContainsString('LLM API timeout', $data['error']);
    }

    public function testMessageAction403ReturnsFirewallMessage(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getProvider')->willReturn('openai');

        $this->agent->method('run')
            ->willThrowException(new \RuntimeException(
                'HTTP/1.1 403 Forbidden returned for "https://api.openai.com/v1/chat/completions".'
            ));

        $request = new Request([], [], [], [], [], [], json_encode(['message' => 'test']));
        $response = $this->controller->messageAction($request);

        self::assertSame(500, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertStringContainsString('firewall', $data['error']);
        self::assertStringContainsString('api.openai.com', $data['error']);
    }

    public function testMessageAction401ReturnsKeyMessage(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getProvider')->willReturn('openai');

        $this->agent->method('run')
            ->willThrowException(new \RuntimeException(
                'HTTP/1.1 401 Unauthorized returned for "https://api.openai.com/v1/chat/completions".'
            ));

        $request = new Request([], [], [], [], [], [], json_encode(['message' => 'test']));
        $response = $this->controller->messageAction($request);

        $data = json_decode($response->getContent(), true);
        self::assertStringContainsString('Invalid API key', $data['error']);
    }

    public function testMessageAction429ReturnsRateLimitMessage(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getProvider')->willReturn('openai');

        $this->agent->method('run')
            ->willThrowException(new \RuntimeException('HTTP/1.1 429 Too Many Requests'));

        $request = new Request([], [], [], [], [], [], json_encode(['message' => 'test']));
        $response = $this->controller->messageAction($request);

        $data = json_decode($response->getContent(), true);
        self::assertStringContainsString('rate limit', $data['error']);
    }

    public function testMessageActionIncludesProviderResponseBodyAsErrorDetail(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getProvider')->willReturn('gemini');

        $this->agent->method('run')->willThrowException($this->makeClientException(
            400,
            '{"error":{"code":400,"message":"Invalid JSON payload received. Unknown name \"foo\".","status":"INVALID_ARGUMENT"}}'
        ));

        $request = new Request([], [], [], [], [], [], json_encode(['message' => 'test']));
        $response = $this->controller->messageAction($request);

        $data = json_decode($response->getContent(), true);
        self::assertStringContainsString('Unknown name', $data['error_detail']);
        self::assertStringContainsString('INVALID_ARGUMENT', $data['error_detail']);
    }

    public function testMessageActionOmitsErrorDetailForNonHttpExceptions(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $this->agent->method('run')->willThrowException(new \RuntimeException('Something unrelated broke.'));

        $request = new Request([], [], [], [], [], [], json_encode(['message' => 'test']));
        $response = $this->controller->messageAction($request);

        $data = json_decode($response->getContent(), true);
        self::assertArrayHasKey('error_detail', $data);
        self::assertNull($data['error_detail']);
    }

    private function makeClientException(int $statusCode, string $body): ClientException
    {
        $client = new MockHttpClient(new MockResponse($body, ['http_code' => $statusCode]));
        $response = $client->request('POST', 'https://generativelanguage.googleapis.com/v1beta/models/test:generateContent');

        return new ClientException($response);
    }

    public function testStatusActionReturnsConfiguredFalse(): void
    {
        $this->config->method('isConfigured')->willReturn(false);
        $this->config->method('getProvider')->willReturn('openai');

        $response = $this->controller->statusAction();

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertFalse($data['configured']);
        self::assertSame('openai', $data['provider']);
    }

    public function testStatusActionReturnsConfiguredTrue(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getProvider')->willReturn('anthropic');

        $response = $this->controller->statusAction();

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertTrue($data['configured']);
        self::assertSame('anthropic', $data['provider']);
    }

    public function testMessageActionParsesHistory(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $this->agent->expects(self::once())
            ->method('run')
            ->willReturnCallback(function (string $msg, array $history) {
                self::assertCount(2, $history);
                self::assertSame('user', $history[0]->role->value);
                self::assertSame('previous question', $history[0]->content);
                self::assertSame('assistant', $history[1]->role->value);
                self::assertSame('previous answer', $history[1]->content);

                return new AgentResult('follow-up answer', [], []);
            });

        $request = new Request([], [], [], [], [], [], json_encode([
            'message' => 'follow up',
            'history' => [
                ['role' => 'user', 'content' => 'previous question'],
                ['role' => 'assistant', 'content' => 'previous answer'],
            ],
        ]));

        $response = $this->controller->messageAction($request);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testMessageActionIgnoresInvalidHistoryRoles(): void
    {
        $this->config->method('isConfigured')->willReturn(true);

        $this->agent->expects(self::once())
            ->method('run')
            ->willReturnCallback(function (string $msg, array $history) {
                self::assertCount(1, $history);

                return new AgentResult('ok', [], []);
            });

        $request = new Request([], [], [], [], [], [], json_encode([
            'message' => 'test',
            'history' => [
                ['role' => 'invalid_role', 'content' => 'should be skipped'],
                ['role' => 'user', 'content' => 'valid'],
            ],
        ]));

        $this->controller->messageAction($request);
    }
}
