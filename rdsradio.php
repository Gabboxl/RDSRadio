#!/usr/bin/env php
<?php

set_include_path(get_include_path().':'.realpath(dirname(__FILE__).'/MadelineProto/'));

if (!file_exists(__DIR__.'/vendor/autoload.php')) {
    echo 'You did not run composer update, using madeline.php'.PHP_EOL;
    if (!file_exists('madeline.php')) {
        copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
    }
    include 'madeline.php';
} else {
    require_once 'vendor/autoload.php';
}

echo 'Deserializing MadelineProto from session.madeline...'.PHP_EOL;

use danog\Loop\ResumableSignalLoop;

//use danog\MadelineProto\EventHandler;

class MessageLoop extends ResumableSignalLoop
{
    const INTERVAL = 10000;
    private $timeout;
    private $call;
    private EventHandler $API;

    public function __construct($API, $call, $timeout = self::INTERVAL)
    {
        $this->API = $API;
        $this->call = $call;
        $this->timeout = $timeout;
    }

    public function loop(): \Generator
    {
        $MadelineProto = $this->API;
        $logger = $MadelineProto->getLogger();

        while (true) {
            do {
                $result = yield $this->waitSignal($this->pause($this->timeout));
                if ($result) {
                    $logger->logger("Got signal in $this, exiting");

                    return;
                }
            } while (!isset($this->call->mId));

            $result = yield $this->waitSignal($this->pause($this->timeout));

            try {
                if ($MadelineProto->jsonmoseca != $MadelineProto->nowPlaying('jsonclear')) { //anti-floodwait

                    yield $MadelineProto->messages->editMessage(['id' => $this->call->mId, 'peer' => $this->call->getOtherID(), 'message' => 'Stai ascoltando: <b>'.$MadelineProto->nowPlaying()[1].'</b>  '.$MadelineProto->nowPlaying()[2].'<br> Tipo: <i>'.$MadelineProto->nowPlaying()[0].'</i>', 'parse_mode' => 'html']);
                    //anti-floodwait
                    $MadelineProto->jsonmoseca = $MadelineProto->nowPlaying('jsonclear');
                }
            } catch (\danog\MadelineProto\Exception | \danog\MadelineProto\RPCErrorException $e) {
                $logger->logger($e);
            }
        }
    }

    public function __toString(): string
    {
        return 'VoIP message loop '.$this->call->getOtherId();
    }
}
class StatusLoop extends ResumableSignalLoop
{
    const INTERVAL = 2000;
    private $timeout;
    private $call;
    private EventHandler $API;

    public function __construct($API, $call, $timeout = self::INTERVAL)
    {
        $this->API = $API;
        $this->call = $call;
        $this->timeout = $timeout;
    }

    public function loop(): \Generator
    {
        $MadelineProto = $this->API;
        $logger = $MadelineProto->getLogger();
        $call = $this->call;

        while (true) {
            $result = yield $this->waitSignal($this->pause($this->timeout));
            if ($result) {
                $logger->logger("Got signal in $this, exiting");
                $MadelineProto->getEventHandler()->cleanUpCall($call->getOtherID());

                return;
            }

            //  \danog\MadelineProto\Logger::log(count(yield $MadelineProto->getEventHandler()->calls).' calls running!');

            if ($call->getCallState() === \danog\MadelineProto\VoIP::CALL_STATE_ENDED) {
                try {
                    yield $MadelineProto->messages->sendMessage(['no_webpage' => true, 'peer' => $call->getOtherID(), 'message' => "grz x averci scelto \n Contribuisci al progetto: https://github.com/Gabboxl/RDSRadio", 'parse_mode' => 'html']);
                } catch (\danog\MadelineProto\Exception $e) {
                    $logger->logger($e);
                } catch (\danog\MadelineProto\RPCErrorException $e) {
                    $logger->logger($e);
                }
                @unlink('/tmp/logs'.$call->getCallID()['id'].'.log');
                @unlink('/tmp/stats'.$call->getCallID()['id'].'.txt');
                $MadelineProto->getEventHandler()->cleanUpCall($call->getOtherID());

                return;
            }
        }
    }

    public function __toString(): string
    {
        return 'VoIP status loop '.$this->call->getOtherId();
    }
}

class EventHandler extends \danog\MadelineProto\EventHandler
{
    const ADMINS = [218297024]; // @Gabbo_xl
    private array $messageLoops = [];
    private array $statusLoops = [];
    private $programmed_call;
    private $my_users;
    public $calls = [];
    public $jsonmoseca = '';

    public function nowPlaying($returnvariable = null)
    {
        $url = 'https://icstream.rds.radio/status-json.xsl';  //vekkio http://stream1.rds.it:8000/status-json.xsl
        $jsonroba = file_get_contents($url);
        $jsonclear = json_decode($jsonroba, true);
        $metadata = explode('*', $jsonclear['icestats']['source'][16]['title']);

        if ($returnvariable == 'jsonclear') {
            return $jsonclear['icestats']['source'][16]['title'];
        }

        return $metadata;
    }

    public function configureCall($call)
    {
        $icsd = date('U');

        shell_exec('mkdir streams');

        file_put_contents('omg.sh', "#!/bin/bash \n mkfifo streams/$icsd.raw");

        file_put_contents('figo.sh', '#!/bin/bash'." \n".'ffmpeg -i https://icstream.rds.radio/rds -vn -f s16le -ac 1 -ar 48000 -acodec pcm_s16le pipe:1 > streams/'."$icsd.raw"); //https://mediapolis.rai.it/relinker/relinkerServlet.htm?cont=2606803

        shell_exec('chmod -R 0777 figo.sh omg.sh');

        shell_exec('./omg.sh');

        shell_exec("screen -S RDSstream$icsd -dm ./figo.sh");

        $call->configuration['enable_NS'] = false;
        $call->configuration['enable_AGC'] = false;
        $call->configuration['enable_AEC'] = false;
        $call->configuration['log_file_path'] = '/tmp/logs'.$call->getCallID()['id'].'.log'; // Default is /dev/null
        //$call->configuration["stats_dump_file_path"] = "/tmp/stats".$call->getCallID()['id'].".txt"; // Default is /dev/null
        $call->parseConfig();
        $call->playOnHold(["streams/$icsd.raw"]);

        if ($call->getCallState() === \danog\MadelineProto\VoIP::CALL_STATE_INCOMING)
        {
            if (!$res = yield $call->accept()) { //$call->accept() === false
                $this->logger('DID NOT ACCEPT A CALL');
            }
        }

        if ($call->getCallState() !== \danog\MadelineProto\VoIP::CALL_STATE_ENDED) {
            $this->calls[$call->getOtherID()] = $call;

            try {
                $call->mId = yield $this->messages->sendMessage(['no_webpage' => true, 'peer' => $call->getOtherID(), 'message' => 'Stai ascoltando: <b>'.$this->nowPlaying()[1].'</b>  '.$this->nowPlaying()[2].'<br> Tipo: <i>'.$this->nowPlaying()[0].'</i> <br>Stazione: ', 'parse_mode' => 'html'])['id'];
                $this->jsonmoseca = $this->nowPlaying('jsonclear');
            } catch (\Throwable $e) {
                $this->logger($e);
            }
            $this->messageLoops[$call->getOtherID()] = new MessageLoop($this, $call);
            $this->statusLoops[$call->getOtherID()] = new StatusLoop($this, $call);
            $this->messageLoops[$call->getOtherID()]->start();
            $this->statusLoops[$call->getOtherID()]->start();
        }
        //yield $this->messages->sendMessage(['message' => var_export($call->configuration, true), 'peer' => $call->getOtherID()]);
    }

    public function cleanUpCall($user)
    {
        if (isset($this->calls[$user])) {
            unset($this->calls[$user]);
        }
        if (isset($this->messageLoops[$user])) {
            $this->messageLoops[$user]->signal(true);
            unset($this->messageLoops[$user]);
        }
        if (isset($this->statusLoops[$user])) {
            $this->statusLoops[$user]->signal(true);
            unset($this->statusLoops[$user]);
        }
    }

    public function makeCall($user)
    {
        try {
            if (isset($this->calls[$user])) {
                if ($this->calls[$user]->getCallState() === \danog\MadelineProto\VoIP::CALL_STATE_ENDED) {
                    yield $this->cleanUpCall($user);
                } else {
                    yield $this->messages->sendMessage(['peer' => $user, 'message' => 'Sono già in chiamata con te!']);

                    return;
                }
            }
            yield $this->configureCall(yield $this->requestCall($user));
        } catch (\danog\MadelineProto\RPCErrorException $e) {
            try {
                if ($e->rpc === 'USER_PRIVACY_RESTRICTED') {
                    $e = 'Disattiva la privacy delle chiamate nelle impostazioni!';
                } elseif (strpos($e->rpc, 'FLOOD_WAIT_') === 0) {
                    $t = str_replace('FLOOD_WAIT_', '', $e->rpc);
                    $this->programmed_call[] = [$user, time() + 1 + $t];
                    $e = "Ti potrò chiamare tra $t secondi.\nSe vuoi puoi anche chiamarmi direttamente senza aspettare.";
                }
                yield $this->messages->sendMessage(['peer' => $user, 'message' => (string) $e]);
            } catch (\danog\MadelineProto\RPCErrorException $e) {
            }
        } catch (\Throwable $e) {
            yield $this->messages->sendMessage(['peer' => $user, 'message' => (string) $e]);
        }
    }

    public function handleMessage($chat_id, $from_id, $message)
    {
        try {
            if (!isset($this->my_users[$from_id]) || $message === '/start') {
                $this->my_users[$from_id] = true;
                yield $this->messages->sendMessage(['no_webpage'                    => true, 'peer' => $chat_id, 'message' => "Ciao! Sono la prima RDS webradio su Telegram! **Chiamami** oppure scrivimi **/call**! \n\nScopri cosa c'è in diretta adesso con **/nowplaying**! \n\n Scrivimi **/m2o** per passare alla stazione M2O!\n
                Creato con amore da @Gabbo_xl usando @madelineproto.", 'parse_mode' => 'Markdown']);
            }

            if (!isset($this->calls[$from_id]) && $message === '/call') {
                yield $this->makeCall($from_id);
            }

            if (!isset($this->my_users[$from_id]) || $message === '/nowplaying') {
                $this->my_users[$from_id] = true;
                yield $this->messages->sendMessage(['no_webpage' => true, 'peer' => $chat_id, 'message' => '🔴ORA in ONDA: <b>'.$this->nowPlaying()[1].'</b>  '.$this->nowPlaying()[2].'<br> Tipo: <i>'.$this->nowPlaying()[0].'</i>', 'parse_mode' => 'html']);
            }

            if (!isset($this->my_users[$from_id]) || $message === '/m2o') {
                $this->my_users[$from_id] = true;
                if (isset($this->calls[$from_id])) {
                    $icsd2 = date('U');

                    shell_exec('mkdir streams');

                    file_put_contents('omg.sh', "#!/bin/bash \n mkfifo streams/$icsd2.raw");

                    file_put_contents('figo.sh', '#!/bin/bash'." \n".'ffmpeg -i https://radiom2o-lh.akamaihd.net/i/RadioM2o_Live_1@42518/master.m3u8 -vn -f s16le -ac 1 -ar 48000 -acodec pcm_s16le pipe:1 > streams/'."$icsd2.raw");

                    shell_exec('chmod -R 0777 figo.sh omg.sh');

                    shell_exec('./omg.sh');

                    shell_exec("screen -S M2Ostream$icsd2 -dm ./figo.sh");

                    $this->calls[$from_id]->playOnHold(["streams/$icsd2.raw"]);

                    yield $this->messages->sendMessage(['no_webpage' => true, 'peer' => $chat_id, 'message' => 'Caricamento... Buon ascolto di Radio M2O :)', 'parse_mode' => 'html']);
                } else {
                    yield $this->messages->sendMessage(['no_webpage' => true, 'peer' => $chat_id, 'message' => 'Non sei in alcuna chiamata!', 'parse_mode' => 'Markdown']);
                }
            }

            if (strpos($message, '/program') === 0) {
                $time = strtotime(str_replace('/program ', '', $message));
                if ($time === false) {
                    yield $this->messages->sendMessage(['peer' => $chat_id, 'message' => 'Orario specificato non valido']);
                } elseif ($time - time() <= 0) {
                    yield $this->messages->sendMessage(['peer' => $chat_id, 'message' => 'Orario specificato non valido']);
                } else {
                    yield $this->messages->sendMessage(['peer' => $chat_id, 'message' => 'OK']);
                    $this->programmed_call[] = [$from_id, $time];
                    $key = count($this->programmed_call) - 1;
                    yield \danog\MadelineProto\Tools::sleep($time - time());
                    yield $this->makeCall($from_id);
                    unset($this->programmed_call[$key]);
                }
            }
            if ($message === '/broadcast' && in_array(self::ADMINS, $from_id)) {
                $time = time() + 100;
                $message = explode(' ', $message, 2);
                unset($message[0]);
                $message = implode(' ', $message);
                $params = ['multiple' => true];
                foreach (yield $this->getDialogs() as $peer) {
                    $params[] = ['peer' => $peer, 'message' => $message];
                }
                yield $this->messages->sendMessage($params);
            }
        } catch (\danog\MadelineProto\RPCErrorException $e) {
            try {
                if ($e->rpc === 'USER_PRIVACY_RESTRICTED') {
                    $e = 'Disattiva la privacy delle chiamate nelle impostazioni!';
                } /*elseif (strpos($e->rpc, 'FLOOD_WAIT_') === 0) {
                    $t = str_replace('FLOOD_WAIT_', '', $e->rpc);
                    $e = "Too many people used the /call function. I'll be able to call you in $t seconds.\nYou can also call me right now";
                }*/
                yield $this->messages->sendMessage(['peer' => $chat_id, 'message' => (string) $e]);
            } catch (\danog\MadelineProto\RPCErrorException $e) {
            }
            $this->logger($e);
        } catch (\danog\MadelineProto\Exception $e) {
            $this->logger($e);
        }
    }

    public function onUpdateNewMessage($update)
    {
        if ($update['message']['out'] || $update['message']['to_id']['_'] !== 'peerUser' || !isset($update['message']['from_id'])) {
            return;
        }
        $this->logger($update);
        $chat_id = $from_id = yield $this->getInfo($update)['bot_api_id'];
        $message = $update['message']['message'] ?? '';
        yield $this->handleMessage($chat_id, $from_id, $message);
    }

    public function onUpdateNewEncryptedMessage($update)
    {

        /* $chat_id = yield $this->getInfo($update)['InputEncryptedChat'];
        $from_id = yield $this->getSecretChat($chat_id)['user_id'];
        $message = isset($update['message']['decrypted_message']['message']) ? $update['message']['decrypted_message']['message'] : '';
        yield $this->handleMessage($chat_id, $from_id, $message); */
    }

    public function onUpdateEncryption($update)
    {

       /* try {
            if ($update['chat']['_'] !== 'encryptedChat') {
                return;
            }
            $chat_id = yield $this->getInfo($update)['InputEncryptedChat'];
            $from_id = yield $this->getSecretChat($chat_id)['user_id'];
            $message = '';
        } catch (\danog\MadelineProto\Exception $e) {
            return;
        }
        yield $this->handleMessage($chat_id, $from_id, $message); */
    }

    public function onUpdatePhoneCall($update)
    {
        if (is_object($update['phone_call']) && isset($update['phone_call']->madeline) && $update['phone_call']->getCallState() === \danog\MadelineProto\VoIP::CALL_STATE_INCOMING) {
            yield $this->configureCall($update['phone_call']);
        }

        if(is_object($update['phone_call']) && isset($update['phone_call']->madeline) && $update['phone_call']->getCallState() === \danog\MadelineProto\VoIP::CALL_STATE_READY){
          try {
              yield $this->messages->sendMessage(['peer' => $update['phone_call']->getOtherID(), 'message' => 'Emojis: '.implode('', $update['phone_call']->getVisualization())]);
          } catch (\danog\MadelineProto\Exception $e) {
              $this->logger($e);
              yield $this->messages->sendMessage(['peer' => $update['phone_call']->getOtherID(), 'message' => 'non sono riuscito a prendere le emoji']);
          }
      }

    }

    /*public function onAny($update)
    {
        $this->logger($update);
    }*/

    public function __construct($API)
    {
        parent::__construct($API);
        $this->programmed_call = [];
        foreach ($this->programmed_call as $key => list($user, $time)) {
            continue;
            $sleepTime = $time <= time() ? 0 : $time - time();
            \danog\MadelineProto\Tools::callFork((function () use ($sleepTime, $key, $user) {
                yield \danog\MadelineProto\Tools::sleep($sleepTime);
                yield $this->makeCall($user);
                unset($this->programmed_call[$key]);
            })());
        }
    }

    public function __sleep()
    {
        return ['programmed_call', 'my_users'];
    }
}

if (!class_exists('\\danog\\MadelineProto\\VoIPServerConfig')) {
    exit("Installa l'estensione libtgvoip: https://voip.madelineproto.xyz".PHP_EOL);
}

\danog\MadelineProto\VoIPServerConfig::update(
    [
        'audio_init_bitrate'      => 100 * 1000,
        'audio_max_bitrate'       => 100 * 1000,
        'audio_min_bitrate'       => 10 * 1000,
        'audio_congestion_window' => 4 * 1024,
    ]
);
$MadelineProto = new \danog\MadelineProto\API('session.madeline', ['secret_chats' => ['accept_chats' => false], 'logger' => ['logger' => 3, 'logger_level' => 5, 'logger_param' => getcwd().'/MadelineProto.log'], 'updates' => ['getdifference_interval' => 10], 'serialization' => ['serialization_interval' => 30, 'cleanup_before_serialization' => true], 'flood_timeout' => ['wait_if_lt' => 86400]]);
foreach (['calls', 'programmed_call', 'my_users'] as $key) {
    if (isset($MadelineProto->API->storage[$key])) {
        unset($MadelineProto->API->storage[$key]);
    }
}

$MadelineProto->async(true);
$MadelineProto->loop(function () use ($MadelineProto) {
    yield $MadelineProto->start();
    yield $MadelineProto->setEventHandler('\EventHandler');
});
$MadelineProto->loop();
