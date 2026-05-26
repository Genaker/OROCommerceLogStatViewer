<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Service;

/**
 * Collects real-time server performance metrics for the performance dashboard.
 *
 * Gathers: hostname, IP, CPU load averages, memory stats, top-10 processes.
 * All data comes from /proc filesystem — no external dependencies required.
 */
class ServerMetricsCollector
{
    public const int TTL_SECONDS = 1800;
    public const int TOP_PROCESS_COUNT = 10;

    /**
     * Returns a complete snapshot of this instance's metrics.
     *
     * @return array{
     *     instanceId: string,
     *     hostname: string,
     *     ip: string,
     *     collectedAt: string,
     *     load: array{m1: float, m5: float, m15: float},
     *     memory: array{total: int, free: int, available: int, used: int, usedPct: float},
     *     cpu: array{cores: int},
     *     processes: list<array{pid: string, user: string, cpu: string, mem: string, command: string}>
     * }
     */
    public function collect(): array
    {
        $hostname = (string) gethostname();
        $ip = $this->resolveIp($hostname);

        return [
            'instanceId'  => substr(md5($hostname . $ip), 0, 12),
            'hostname'    => $hostname,
            'ip'          => $ip,
            'collectedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'load'        => $this->collectLoad(),
            'memory'      => $this->collectMemory(),
            'cpu'         => $this->collectCpu(),
            'processes'   => $this->collectTopProcesses(),
        ];
    }

    private function resolveIp(string $hostname): string
    {
        $serverAddr = $_SERVER['SERVER_ADDR'] ?? $_ENV['SERVER_ADDR'] ?? '';
        if ($serverAddr !== '' && $serverAddr !== '::1' && $serverAddr !== '127.0.0.1') {
            return $serverAddr;
        }

        $resolved = gethostbyname($hostname);

        return $resolved !== $hostname ? $resolved : '127.0.0.1';
    }

    /**
     * @return array{m1: float, m5: float, m15: float}
     */
    private function collectLoad(): array
    {
        $load = sys_getloadavg();

        return [
            'm1'  => round((float) ($load[0] ?? 0.0), 2),
            'm5'  => round((float) ($load[1] ?? 0.0), 2),
            'm15' => round((float) ($load[2] ?? 0.0), 2),
        ];
    }

    /**
     * @return array{total: int, free: int, available: int, used: int, usedPct: float}
     */
    private function collectMemory(): array
    {
        $raw = @file_get_contents('/proc/meminfo');
        if ($raw === false) {
            return ['total' => 0, 'free' => 0, 'available' => 0, 'used' => 0, 'usedPct' => 0.0];
        }

        $values = [];
        foreach (explode("\n", $raw) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s+kB/', $line, $matches)) {
                $values[$matches[1]] = (int) $matches[2];
            }
        }

        $total     = $values['MemTotal'] ?? 0;
        $available = $values['MemAvailable'] ?? ($values['MemFree'] ?? 0);
        $used      = $total - $available;

        return [
            'total'     => $total,
            'free'      => $values['MemFree'] ?? 0,
            'available' => $available,
            'used'      => $used,
            'usedPct'   => $total > 0 ? round(($used / $total) * 100, 1) : 0.0,
        ];
    }

    /**
     * @return array{cores: int}
     */
    private function collectCpu(): array
    {
        $cores = 0;
        $raw = @file_get_contents('/proc/cpuinfo');
        if ($raw !== false) {
            $cores = substr_count($raw, 'processor');
        }

        return ['cores' => max(1, $cores)];
    }

    /**
     * Reads process info from /proc filesystem (no ps required).
     *
     * @return list<array{pid: string, user: string, cpu: string, mem: string, command: string}>
     */
    private function collectTopProcesses(): array
    {
        $uptimeRaw = @file_get_contents('/proc/uptime');
        if ($uptimeRaw === false) {
            return [];
        }
        $uptimeSeconds = (float) explode(' ', trim($uptimeRaw))[0];
        $clkTck = 100; // Hz — sysconf(_SC_CLK_TCK), standard Linux default

        $memTotal = $this->readMemTotal();

        $processes = [];
        $procDirs = glob('/proc/[0-9]*', GLOB_ONLYDIR);
        if ($procDirs === false) {
            return [];
        }

        foreach ($procDirs as $procDir) {
            $pid = basename($procDir);
            $statRaw = @file_get_contents($procDir . '/stat');
            if ($statRaw === false) {
                continue;
            }

            // /proc/<pid>/stat: pid (comm) state ppid ... utime stime ...
            // Fields are space-separated; comm may contain spaces and is enclosed in ()
            $statRaw = preg_replace('/^\d+ \(.*?\) /', $pid . ' cmd ', $statRaw);
            $statParts = explode(' ', trim((string) $statRaw));
            if (count($statParts) < 15) {
                continue;
            }

            $utimeTicks = (int) ($statParts[13] ?? 0);
            $stimeTicks = (int) ($statParts[14] ?? 0);
            $startTimeTicks = (int) ($statParts[21] ?? 0);

            $processSeconds = $uptimeSeconds - ($startTimeTicks / $clkTck);
            if ($processSeconds <= 0) {
                continue;
            }

            $cpuUsage = (($utimeTicks + $stimeTicks) / $clkTck) / $processSeconds * 100;

            $vmRssKb = $this->readVmRss($procDir);
            $memPct = $memTotal > 0 ? ($vmRssKb / $memTotal * 100) : 0.0;

            $cmdline = @file_get_contents($procDir . '/cmdline');
            $command = $cmdline !== false && $cmdline !== ''
                ? mb_substr(str_replace("\0", ' ', trim($cmdline)), 0, 60)
                : '[kernel]';

            $user = $this->readProcessUser($procDir);

            $processes[] = [
                'pid'     => $pid,
                'user'    => $user,
                'cpu'     => round($cpuUsage, 1),
                'mem'     => round($memPct, 1),
                'command' => $command,
            ];
        }

        usort($processes, static fn (array $a, array $b) => $b['cpu'] <=> $a['cpu']);

        return array_slice($processes, 0, self::TOP_PROCESS_COUNT);
    }

    private function readMemTotal(): int
    {
        $raw = @file_get_contents('/proc/meminfo');
        if ($raw === false) {
            return 0;
        }
        if (preg_match('/MemTotal:\s+(\d+)/', $raw, $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }

    private function readVmRss(string $procDir): int
    {
        $raw = @file_get_contents($procDir . '/status');
        if ($raw === false) {
            return 0;
        }
        if (preg_match('/VmRSS:\s+(\d+)/', $raw, $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }

    private function readProcessUser(string $procDir): string
    {
        $statusRaw = @file_get_contents($procDir . '/status');
        if ($statusRaw === false) {
            return '?';
        }
        if (preg_match('/Uid:\s+(\d+)/', $statusRaw, $matches)) {
            $uid = (int) $matches[1];
            $passwdEntry = @posix_getpwuid($uid);
            if (is_array($passwdEntry) && isset($passwdEntry['name'])) {
                return $passwdEntry['name'];
            }
            return (string) $uid;
        }
        return '?';
    }
}
