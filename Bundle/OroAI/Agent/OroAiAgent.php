<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Agent;

use Genaker\Bundle\OroAI\Core\Model\AgentResult;
use Genaker\Bundle\OroAI\Core\Model\ChatMessage;
use Genaker\Bundle\OroAI\Core\Model\LlmRequest;
use Genaker\Bundle\OroAI\Core\Model\LlmResponse;
use Genaker\Bundle\OroAI\Core\Model\Role;
use Genaker\Bundle\OroAI\Core\Model\ToolResult;
use Genaker\Bundle\OroAI\Llm\LlmClientRegistry;
use Genaker\Bundle\OroAI\Rag\RagStoreInterface;
use Genaker\Bundle\OroAI\Service\OroAiConfig;

/** Agentic loop that drives LLM tool-use to answer administrator questions. */
class OroAiAgent
{
    public function __construct(
        private readonly LlmClientRegistry $registry,
        private readonly ToolRegistry $toolRegistry,
        private readonly RagStoreInterface $ragStore,
        private readonly OroAiConfig $config,
        private readonly GuidelineProviderInterface $guidelineProvider,
    ) {
    }

    private const array ZERO_USAGE = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];

    /**
     * @param ChatMessage[] $history
     * @param (callable(array): void)|null $onProgress Invoked with a step
     *        event (e.g. {type: 'tool_call', tool, args} / {type: 'tool_result',
     *        tool, success}) as each tool executes, so a caller can surface a
     *        live "what is it doing right now" checklist instead of a single
     *        opaque loading indicator for the whole (potentially multi-turn,
     *        multi-tool) run.
     */
    public function run(string $userMessage, array $history = [], ?callable $onProgress = null): AgentResult
    {
        $tools = $this->toolRegistry->definitions();
        $messages = [];

        $customInstructions = $this->config->getCustomInstructions();
        if ($customInstructions !== '') {
            $messages[] = ChatMessage::system($customInstructions);
        }

        $messages[] = ChatMessage::system($this->buildSystemPrompt());

        if ($this->config->isRagEnabled()) {
            try {
                $hits = $this->ragStore->search($userMessage, $this->config->getRagTopK());
                foreach ($hits as $hit) {
                    $messages[] = ChatMessage::system("[DOC {$hit->source}]\n{$hit->text}");
                }
            } catch (\Throwable) {
                // intentional
            }
        }

        $messages = array_merge($messages, $history, [ChatMessage::user($userMessage)]);

        $client = $this->registry->get();
        $trace = [];
        $usage = self::ZERO_USAGE;

        for ($i = 0; $i < $this->config->getMaxIterations(); $i++) {
            $resp = $client->chat(new LlmRequest($messages, $tools, $this->config->getTemperature()));
            $usage = LlmResponse::sumUsage($usage, $resp->usage);

            if (!$resp->toolCalls) {
                return new AgentResult($resp->content, $trace, $this->extractLinks($trace), $usage);
            }

            $messages[] = ChatMessage::assistantToolCalls($resp);

            foreach ($resp->toolCalls as $call) {
                $args = json_decode($call->argsJson, true) ?? [];
                if ($onProgress !== null) {
                    $onProgress(['type' => 'tool_call', 'tool' => $call->name, 'args' => $args]);
                }

                try {
                    $out = $this->toolRegistry->execute($call->name, $args);
                } catch (\Throwable $e) {
                    $out = ToolResult::error($e->getMessage());
                }

                if ($onProgress !== null) {
                    $onProgress(['type' => 'tool_result', 'tool' => $call->name, 'success' => $out->success]);
                }

                $trace[] = [
                    'tool' => $call->name,
                    'args' => $call->argsJson,
                    'result' => $out->summary(),
                ];

                $messages[] = ChatMessage::toolResult($call->id, $out->toJson(), $call->name);
            }
        }

        $lastResult = $trace ? json_encode(end($trace)['result']) : 'No results.';

        return new AgentResult(
            'I could not complete the request within the allowed number of steps. '
            . 'Here is what I found so far: ' . $lastResult,
            $trace,
            $this->extractLinks($trace),
            $usage,
        );
    }

    private function buildSystemPrompt(): string
    {
        $toolNames = implode(', ', $this->toolRegistry->names());
        $toolGuidelines = $this->buildToolGuidelines();
        $generalGuidelines = $this->buildGeneralGuidelines();

        return <<<PROMPT
You are an AI assistant for OroCommerce administrators.
You help with questions about the platform, finding data, and navigating the admin panel.

Available tools: {$toolNames}

When to use each tool (this list is generated from the tools' own definitions, so any
tool added to the system automatically appears here with its own guidance):
{$toolGuidelines}

General guidelines (generated from every registered bundle's own
oro_ai_guidelines.yml, so any bundle can add to this list without editing this class):
{$generalGuidelines}
PROMPT;
    }

    /**
     * Renders "- tool_name: description" for every enabled tool, straight from
     * each tool's own ToolDefinition — so a new tool's usage guidance lives
     * entirely in its own class and shows up here automatically, instead of
     * needing a matching edit to this hardcoded prompt.
     */
    private function buildToolGuidelines(): string
    {
        $lines = array_map(
            static fn($definition) => '- ' . $definition->name . ': ' . $definition->description,
            $this->toolRegistry->definitions(),
        );

        return implode("\n", $lines);
    }

    /**
     * Renders "- guideline text" from GuidelineProvider, which merges
     * oro_ai_guidelines.yml across every registered bundle — so a bundle can
     * add a general (non-tool-specific) rule just by dropping that file,
     * instead of needing a matching edit to this hardcoded prompt.
     */
    private function buildGeneralGuidelines(): string
    {
        $lines = array_map(
            static fn(string $guideline) => '- ' . $guideline,
            $this->guidelineProvider->getGuidelines(),
        );

        return implode("\n", $lines);
    }

    private function extractLinks(array $trace): array
    {
        $links = [];
        foreach ($trace as $entry) {
            if (is_string($entry['result'])) {
                preg_match_all('#/admin/[^\s"\'<>]+#', $entry['result'], $matches);
                foreach ($matches[0] as $url) {
                    $links[] = $url;
                }
            }
        }

        return array_values(array_unique($links));
    }
}
