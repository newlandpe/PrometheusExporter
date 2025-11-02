<?php
declare(strict_types=1);

namespace ChernegaSergiy\PrometheusExporter;

use pocketmine\Server;

final class MetricsStore{
    private Server $server;
    private array $config;

    private int $joins = 0;
    private int $quits = 0;
    private int $playersOnline = 0;
    private ?int $lastJoinPing = null;

    private float $currentTps = 20.0;
    private float $averageTps = 20.0;
    private float $currentTickUsage = 0.0;
    private float $averageTickUsage = 0.0;

    private int $phpMemoryUsage = 0;
    private int $phpMemoryReal = 0;
    private int $phpMemoryPeak = 0;

    public function __construct(Server $server, array $config, array $persistent = []){
        $this->server = $server;
        $this->config = $config;
        $this->restorePersistentData($persistent);
    }

    public function update() : void{
        $this->playersOnline = count($this->server->getOnlinePlayers());
        $this->currentTps = $this->server->getTicksPerSecond();
        $this->averageTps = $this->server->getTicksPerSecondAverage();
        $this->currentTickUsage = $this->server->getTickUsage();
        $this->averageTickUsage = $this->server->getTickUsageAverage();

        if(($this->config["include_memory_details"] ?? true) === true){
            $this->phpMemoryUsage = memory_get_usage();
            $this->phpMemoryReal = memory_get_usage(true);
            $this->phpMemoryPeak = memory_get_peak_usage(true);
        }
    }

    public function recordPlayerJoin(int $ping) : void{
        ++$this->joins;
        $this->lastJoinPing = $ping;
    }

    public function recordPlayerQuit() : void{
        ++$this->quits;
    }

    public function render() : string{
        $lines = [];

        $lines[] = '# HELP pocketmine_players_online Online players';
        $lines[] = '# TYPE pocketmine_players_online gauge';
        $lines[] = 'pocketmine_players_online ' . $this->playersOnline;

        $lines[] = '# HELP pocketmine_joins_total Player joins';
        $lines[] = '# TYPE pocketmine_joins_total counter';
        $lines[] = 'pocketmine_joins_total ' . $this->joins;

        $lines[] = '# HELP pocketmine_quits_total Player quits';
        $lines[] = '# TYPE pocketmine_quits_total counter';
        $lines[] = 'pocketmine_quits_total ' . $this->quits;

        if($this->lastJoinPing !== null){
            $lines[] = '# HELP pocketmine_last_join_ping_ms Ping of the last player to join';
            $lines[] = '# TYPE pocketmine_last_join_ping_ms gauge';
            $lines[] = 'pocketmine_last_join_ping_ms ' . $this->lastJoinPing;
        }

        $lines[] = '# HELP pocketmine_tps_current Current server TPS';
        $lines[] = '# TYPE pocketmine_tps_current gauge';
        $lines[] = 'pocketmine_tps_current ' . $this->currentTps;

        $lines[] = '# HELP pocketmine_tps_average Average server TPS';
        $lines[] = '# TYPE pocketmine_tps_average gauge';
        $lines[] = 'pocketmine_tps_average ' . $this->averageTps;

        $lines[] = '# HELP pocketmine_tick_usage_current Current server tick usage percent';
        $lines[] = '# TYPE pocketmine_tick_usage_current gauge';
        $lines[] = 'pocketmine_tick_usage_current ' . $this->currentTickUsage;

        $lines[] = '# HELP pocketmine_tick_usage_average Average server tick usage percent';
        $lines[] = '# TYPE pocketmine_tick_usage_average gauge';
        $lines[] = 'pocketmine_tick_usage_average ' . $this->averageTickUsage;

        if(($this->config["include_memory_details"] ?? true) === true){
            $lines[] = '# HELP pocketmine_memory_usage_bytes PHP memory usage';
            $lines[] = '# TYPE pocketmine_memory_usage_bytes gauge';
            $lines[] = 'pocketmine_memory_usage_bytes ' . $this->phpMemoryUsage;

            $lines[] = '# HELP pocketmine_memory_real_bytes Real memory allocated by PHP';
            $lines[] = '# TYPE pocketmine_memory_real_bytes gauge';
            $lines[] = 'pocketmine_memory_real_bytes ' . $this->phpMemoryReal;

            $lines[] = '# HELP pocketmine_memory_peak_bytes Peak memory usage';
            $lines[] = '# TYPE pocketmine_memory_peak_bytes gauge';
            $lines[] = 'pocketmine_memory_peak_bytes ' . $this->phpMemoryPeak;
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return array<string, int|null>
     */
    public function getPersistentSnapshot() : array{
        return [
            "joins" => $this->joins,
            "quits" => $this->quits,
            "last_join_ping" => $this->lastJoinPing,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function restorePersistentData(array $data) : void{
        if(isset($data["joins"])){
            $this->joins = max(0, (int) $data["joins"]);
        }

        if(isset($data["quits"])){
            $this->quits = max(0, (int) $data["quits"]);
        }

        if(array_key_exists("last_join_ping", $data) && $data["last_join_ping"] !== null){
            $this->lastJoinPing = (int) $data["last_join_ping"];
        }
    }
}
