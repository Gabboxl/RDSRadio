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

class EventHandler extends \danog\MadelineProto\EventHandler
{
  public function nowPlaying($returnvariable = null)
  {
      $url = 'https://icstream.rds.radio/status-json.xsl';  //vekkio http://stream1.rds.it:8000/status-json.xsl
      $jsonroba = file_get_contents($url);
      $jsonclear = json_decode($jsonroba, true);
      $metadata = explode('*', $jsonclear['icestats']['source'][15]['title']);

      //anti-floodwait
      file_put_contents('testmoseca.php', $jsonclear['icestats']['source'][15]['title']);
      if ($returnvariable == 'jsonclear') {
          return $jsonclear['icestats']['source'][15]['title'];
      }

      return $metadata;
  }

    public function configureCall($call)
    {
      $icsd = date('U');

      shell_exec('mkdir streams');

      file_put_contents('omg.sh', "#!/bin/bash \n mkfifo streams/$icsd.raw");

      file_put_contents('figo.sh', '#!/bin/bash'." \n".'ffmpeg -i "http://stream1.rds.it:8000/apprds128" -vn -f s16le -ac 1 -ar 48000 -acodec pcm_s16le pipe:1 > streams/'."$icsd.raw"); //https://mediapolis.rai.it/relinker/relinkerServlet.htm?cont=2606803

      shell_exec("sudo chmod -R 0777 omg.sh figo.sh");

      shell_exec('./omg.sh');

      shell_exec("screen -S RDSstream$icsd -dm ./figo.sh");

      $call->configuration['enable_NS'] = false;
      $call->configuration['enable_AGC'] = false;
      $call->configuration['enable_AEC'] = false;
      $call->configuration['shared_config'] = [
          'audio_init_bitrate'      => 100 * 1000,
          'audio_max_bitrate'       => 100 * 1000,
          'audio_min_bitrate'       => 10 * 1000,
          'audio_congestion_window' => 4 * 1024,
          //'audio_bitrate_step_decr' => 0,
          //'audio_bitrate_step_incr' => 2000,
      ];
      $call->parseConfig();
      $call->playOnHold(["streams/$icsd.raw"]);
    }

    public function handleMessage($chat_id, $from_id, $message)
    {
        try {
            if (!isset($this->my_users[$from_id]) || $message === '/start') {
                $this->my_users[$from_id] = true;
                $this->messages->sendMessage(['no_webpage' => true, 'peer' => $chat_id, 'message' => "Ciao! Sono la prima RDS webradio su Telegram! <b>Chiamami</b> oppure scrivimi <b>/call</b>! \n
                \n Creato con amore da @Gabbo_xl usando @madelineproto.", 'parse_mode' => 'html']);
            }
            if (!isset($this->calls[$from_id]) && $message === '/call') {
                $call = $this->request_call($from_id);
                $this->configureCall($call);
                if ($call->getCallState() !== \danog\MadelineProto\VoIP::CALL_STATE_ENDED) {
                    $this->calls[$call->getOtherID()] = $call;
                    $this->times[$call->getOtherID()] = [time(), $this->messages->sendMessage(['peer' => $call->getOtherID(), 'message' => 'sej un .'])['id']];
                }
            }
            if (strpos($message, '/program') === 0) {
                $time = strtotime(str_replace('/program ', '', $message));
                if ($time === false) {
                    $this->messages->sendMessage(['peer' => $chat_id, 'message' => 'Invalid time provided']);
                } else {
                    $this->programmed_call[] = [$from_id, $time];
                    $this->messages->sendMessage(['peer' => $chat_id, 'message' => 'OK']);
                }
            }
        } catch (\danog\MadelineProto\RPCErrorException $e) {
            try {
                if ($e->rpc === 'USER_PRIVACY_RESTRICTED') {
                    $e = 'Disattiva la privacy delle chiamate nelle impostazioni!';
                } elseif (strpos($e->rpc, 'FLOOD_WAIT_') === 0) {
                    $t = str_replace('FLOOD_WAIT_', '', $e->rpc);
                    $this->programmed_call[] = [$from_id, time() + 1 + $t];
                    $e = "Ti potrò chiamare tra $t secondi.\nSe vuoi puoi anche chiamarmi direttamente senza aspettare.";
                }
                $this->messages->sendMessage(['peer' => $chat_id, 'message' => (string) $e]);
            } catch (\danog\MadelineProto\RPCErrorException $e) {
              echo $e;
            }
            echo $e;
        } catch (\danog\MadelineProto\Exception $e) {
            echo $e;
        }
    }

    public function onUpdateNewMessage($update)
    {
        if ($update['message']['out'] || $update['message']['to_id']['_'] !== 'peerUser' || !isset($update['message']['from_id'])) {
            return;
        }
        //\danog\MadelineProto\Logger::log($update);
        $chat_id = $from_id = $this->get_info($update)['bot_api_id'];
        $message = isset($update['message']['message']) ? $update['message']['message'] : '';
        $this->handleMessage($chat_id, $from_id, $message);
    }

    public function onUpdateNewEncryptedMessage($update)
    {
        return;
        $chat_id = $this->get_info($update)['InputEncryptedChat'];
        $from_id = $this->get_secret_chat($chat_id)['user_id'];
        $message = isset($update['message']['decrypted_message']['message']) ? $update['message']['decrypted_message']['message'] : '';
        $this->handleMessage($chat_id, $from_id, $message);
    }

    public function onUpdateEncryption($update)
    {
        return;

        try {
            if ($update['chat']['_'] !== 'encryptedChat') {
                return;
            }
            $chat_id = $this->get_info($update)['InputEncryptedChat'];
            $from_id = $this->get_secret_chat($chat_id)['user_id'];
            $message = '';
        } catch (\danog\MadelineProto\Exception $e) {
            return;
        }
        $this->handleMessage($chat_id, $from_id, $message);
    }

    public function onUpdatePhoneCall($update)
    {
        if (is_object($update['phone_call']) && isset($update['phone_call']->madeline) && $update['phone_call']->getCallState() === \danog\MadelineProto\VoIP::CALL_STATE_INCOMING) {
            $this->configureCall($update['phone_call']);
            if ($update['phone_call']->accept() === false) {
                echo 'DID NOT ACCEPT A CALL';
            }
            $this->calls[$update['phone_call']->getOtherID()] = $update['phone_call'];

            try {
                $this->times[$update['phone_call']->getOtherID()] = [time(), $this->messages->sendMessage(['peer' => $update['phone_call']->getOtherID(), 'message' => 'Se leggi wuesto hai una cacata connexxione (da modificarz)'])['id']];
            } catch (\danog\MadelineProto\RPCErrorException $e) {
            }
        }
    }

    public function onAny($update)
    {
        \danog\MadelineProto\Logger::log($update);
    }

    public function onLoop()
    {
        foreach ($this->programmed_call as $key => $pair) {
            list($user, $time) = $pair;
            if ($time < time()) {
                if (!isset($this->calls[$user])) {
                    try {
                        $call = $this->request_call($user);
                        $this->configureCall($call);
                        if ($call->getCallState() !== \danog\MadelineProto\VoIP::CALL_STATE_ENDED) {
                            $this->calls[$call->getOtherID()] = $call;
                            $this->times[$call->getOtherID()] = [time(), $this->messages->sendMessage(['peer' => $call->getOtherID(), 'message' => 'sucsiamoci (da modificarz)'])['id']];
                        }
                    } catch (\danog\MadelineProto\RPCErrorException $e) {
                        try {
                            if ($e->rpc === 'USER_PRIVACY_RESTRICTED') {
                                $e = 'Disattiva la privacy delle chiamate nelle impostazioni!';
                            } elseif (strpos($e->rpc, 'FLOOD_WAIT_') === 0) {
                                $t = str_replace('FLOOD_WAIT_', '', $e->rpc);
                                $this->programmed_call[] = [$user, time() + 1 + $t];
                                $e = "Ti potrò chiamare tra $t secondi.\nSe vuoi puoi anche chiamarmi direttamente senza aspettare.";
                            }
                            $this->messages->sendMessage(['peer' => $user, 'message' => (string) $e]);
                        } catch (\danog\MadelineProto\RPCErrorException $e) {
                          echo $e;
                        }
                        echo $e;
                    }
                }
                unset($this->programmed_call[$key]);
            }
            break;
        }

        foreach ($this->times_messages as $key => $pair) {
            list($peer, $time, $message) = $pair;
            if ($time < time()) {
                try {
                    $this->messages->sendMessage(['peer' => $peer, 'message' => $message]);
                } catch (\danog\MadelineProto\RPCErrorException $e) {
                    if (strpos($e->rpc, 'FLOOD_WAIT_') === 0) {
                        $t = str_replace('FLOOD_WAIT_', '', $e->rpc);
                        $this->times_messages[] = [$peer, time() + 1 + $t, $message];
                    }
                    echo $e;
                }
                unset($this->times_messages[$key]);
            }
            break;
        }


        $enricopapi = 777;
        $rovazzi = 0;

        if ($enricopapi > $rovazzi) {
            try {
                //now Playing in the name
                                if (file_get_contents('testmoseca.php') == $this->nowPlaying('jsonclear')) { //anti-floodwait
                                    $this->account->updateProfile(['last_name' => '/ Playing: '.$this->nowPlaying()[1].'-'.$this->nowPlaying()[2]]);
                                }
            } catch (\danog\MadelineProto\RPCErrorException | \danog\MadelineProto\Exception $e) {
                // echo $e;
                echo "scaz floodwait x cambio nome . . .   sucsa \n";
            }
        }

        \danog\MadelineProto\Logger::log(count($this->calls).' calls running!');
        foreach ($this->calls as $key => $call) {

          if ($call->getState() === \danog\MadelineProto\VoIP::STATE_WAIT_INIT_ACK) {
                try {
                    $this->messages->sendMessage(['peer' => $call->getOtherID(), 'message' => 'Emojis: '.implode('', $call->getVisualization())]);
                } catch (\danog\MadelineProto\RPCErrorException $e) {
                  echo $e;
                }
              }

            if ($call->getCallState() === \danog\MadelineProto\VoIP::CALL_STATE_ENDED) {
                try {
                    if (isset($this->times[$call->getOtherID()][1])) {
                      $this->messages->sendMessage(['no_webpage' => true, 'peer' => $call->getOtherID(), 'message' => 'grz x averci scelto', 'parse_mode' => 'html']);
                    }
                } catch (\danog\MadelineProto\Exception $e) {
                    echo $e;
                } catch (\danog\MadelineProto\RPCErrorException $e) {
                    echo $e;
                } catch (\danog\MadelineProto\Exception $e) {
                    echo $e;
                }
                @unlink('/tmp/logs'.$call->getCallID()['id'].'.log');
                @unlink('/tmp/stats'.$call->getCallID()['id'].'.txt');
                unset($this->calls[$key]);
            } elseif (isset($this->times[$call->getOtherID()]) && $this->times[$call->getOtherID()][0] < time()) {
                $this->times[$call->getOtherID()][0] += 30 + count($this->calls);

                try {
                    $this->messages->editMessage(['id' => $this->times[$call->getOtherID()][1], 'peer' => $call->getOtherID(), 'message' => 'Stai ascoltando: <b>'.$this->nowPlaying()[1].'</b>  '.$this->nowPlaying()[2].'<br> Tipo: <i>'.$this->nowPlaying()[0].'</i>', 'parse_mode' => 'Markdown']);
                } catch (\danog\MadelineProto\RPCErrorException $e) {
                    echo $e;
                }
            }
        }
    }
}
$MadelineProto = new \danog\MadelineProto\API('session.madeline', ['secret_chats' => ['accept_chats' => false]]);
$MadelineProto->start();

if (!isset($MadelineProto->programmed_call)) {
    $MadelineProto->programmed_call = [];
}

foreach (['my_users', 'times', 'times_messages', 'calls'] as $key) {
    if (!isset($MadelineProto->{$key})) {
        $MadelineProto->{$key} = [];
    }
}

$MadelineProto->setEventHandler('\EventHandler');
$MadelineProto->loop();
