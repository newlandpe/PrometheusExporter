<?php
declare(strict_types=1);

namespace ChernegaSergiy\PrometheusExporter;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;

final class Main extends PluginBase implements Listener{
    private MetricsStore $metrics;
    private ?PrometheusHttpServer $httpServer = null;
    private bool $persistenceDirty = false;

    protected function onEnable() : void{
        $this->saveDefaultConfig();
        @mkdir($this->getDataFolder(), 0777, true);

        $config = $this->getConfig();

        $persistentData = $this->loadPersistentData();

        $this->metrics = new MetricsStore(
            $this->getServer(),
            (array) $config->get("metrics", []),
            $persistentData
        );

        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        $exporterConfig = (array) $config->get("exporter");
        $address = (string) ($exporterConfig["address"] ?? "0.0.0.0");
        $port = (int) ($exporterConfig["port"] ?? 9100);
        $backlog = (int) ($exporterConfig["socket_backlog"] ?? 16);

        $this->metrics->update();

        $this->httpServer = new PrometheusHttpServer(
            $address,
            $port,
            $backlog,
            $this->getLogger(),
            fn() => $this->metrics->render()
        );

        $tickInterval = max(1, (int) ($exporterConfig["tick_interval"] ?? 20));

        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void{
            $this->metrics->update();
            $this->httpServer?->tick();
        }), $tickInterval);

        if($this->httpServer->isReady()){
            $this->getLogger()->info("Prometheus endpoint on http://{$address}:{$port}/metrics");
        }else{
            $this->getLogger()->warning("Prometheus exporter failed to bind socket, metrics endpoint is disabled.");
        }

        if($this->persistenceDirty){
            $this->savePersistentData();
        }
    }

    protected function onDisable() : void{
        $this->savePersistentData();
        $this->httpServer?->shutdown();
        $this->httpServer = null;
    }

    /** @priority LOWEST */
    public function onPlayerJoin(PlayerJoinEvent $event) : void{
        $this->metrics->recordPlayerJoin($event->getPlayer()->getNetworkSession()->getPing());
        $this->markPersistenceDirty();
    }

    /** @priority LOWEST */
    public function onPlayerQuit(PlayerQuitEvent $event) : void{
        $this->metrics->recordPlayerQuit();
        $this->markPersistenceDirty();
    }

    /**
     * @return array<string, scalar|null>
     */
    private function loadPersistentData() : array{
        $path = $this->getDataFolder() . "metrics.json";
        if(!is_file($path)){
            $this->persistenceDirty = true;
            return [];
        }

        $contents = file_get_contents($path);
        if($contents === false){
            $this->getLogger()->warning("Unable to read metrics persistence file, starting fresh.");
            $this->persistenceDirty = true;
            return [];
        }

        $decoded = json_decode($contents, true);
        if(!is_array($decoded)){
            $this->getLogger()->warning("Failed to decode metrics persistence file, starting fresh.");
            $this->persistenceDirty = true;
            return [];
        }

        return $decoded;
    }

    private function savePersistentData() : void{
        if(!$this->persistenceDirty || !isset($this->metrics)){
            return;
        }

        $path = $this->getDataFolder() . "metrics.json";
        $payload = json_encode(
            $this->metrics->getPersistentSnapshot(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );

        if($payload === false){
            $this->getLogger()->warning("Failed to encode metrics persistence payload.");
            return;
        }

        if(@file_put_contents($path, $payload) === false){
            $this->getLogger()->warning("Failed to write metrics persistence file at {$path}");
            return;
        }

        $this->persistenceDirty = false;
    }

    private function markPersistenceDirty() : void{
        $this->persistenceDirty = true;
        $this->savePersistentData();
    }
}
