<?php

namespace naeng\SkinGround;

use naeng\CooltimeCore\CooltimeCore;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChangeSkinEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use pocketmine\utils\SingletonTrait;
use RoMo\ExceptionLogger\ExceptionLogger;
use RoMo\XuidCore\XuidCore;
use SOFe\AwaitGenerator\Await;

class SkinGround extends PluginBase implements Listener{

    use SingletonTrait;

    private string $url = '';
    private string $key = '';

    public function onEnable() : void{
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        $config = $this->getConfig();
        $this->url = $config->get("url", "");
        $this->key = $config->get("key", "");
    }

    public function onLoad() : void{
        self::setInstance($this);
    }

    public function getIcon(int $xuid) : string{
        return $this->url . "/get/{$xuid}_icon.png";
    }

    public function getWallpaper(int $xuid) : string{
        return $this->url . "/get/{$xuid}_wallpaper.png";
    }

    protected function upload(int $xuid, string $skinData) : void{
        $url = $this->url;
        $key = $this->key;

        Server::getInstance()->getAsyncPool()->submitTask(new class($xuid, $skinData, $url, $key) extends AsyncTask{
            private readonly string $data;

            public function __construct(
                private readonly int $xuid,
                string $skinData,
                private readonly string $url,
                string $key
            ){
                $this->data = json_encode([
                    "key" => $key,
                    "xuid" => $xuid,
                    "skin" => base64_encode($skinData)
                ]);
            }

            public function onRun() : void{
                $ch = curl_init($this->url . "/create");

                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // 응답을 문자열로 반환
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']); // JSON 형식으로 전송
                curl_setopt($ch, CURLOPT_POSTFIELDS, $this->data); // JSON 데이터로 전송

                // 요청 실행 및 응답 받기
                $response = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                $this->setResult([$response, $code]);
                curl_close($ch);
            }

            public function onCompletion() : void{
                $result = $this->getResult();
                if(class_exists(ExceptionLogger::class) && $result[1] != 201){
                    ExceptionLogger::handleException(
                        new \Exception("SkinGroundServer에 성공적으로 이미지를 업로드할 수 없습니다: " . $result[0]),
                        XuidCore::getInstance()->getPlayer($this->xuid)
                    );
                    return;
                }
                Await::g2c(CooltimeCore::create("skinground-{$this->xuid}"));
            }
        });
    }

    public function handlePlayerJoinEvent(PlayerJoinEvent $event) : void{
        $player = $event->getPlayer();
        Await::f2c(function() use($player){
            $xuid = $player->getXuid();
            $identifier = "skinground-{$xuid}";
            if(yield from CooltimeCore::check($identifier, 1209600)){ // 1209600: 2주를 초 단위로 변환
                $this->upload($xuid, $player->getSkin()->getSkinData());
            }
        });
    }

    public function handlePlayerChangeSkinEvent(PlayerChangeSkinEvent $event) : void{
        $player = $event->getPlayer();
        $this->upload($player->getXuid(), $player->getSkin()->getSkinData());
    }

}
