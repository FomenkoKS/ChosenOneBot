<?php

include("Telegram.php");




include("config.php");
require_once('service.php');

$telegram = new Telegram(BOT_ID);
$service = new Service($telegram);

$text = $telegram->Text();
$chat_id = $telegram->ChatID();
$message = $telegram->Message();

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

if ($chat_id > 0) {
    if ($text == "/start") {

        $telegram->sendMessage($service->genMenu());        
    }

    if (preg_match('/\d+:.+/', $text, $matches)) {
        $newTg = new Telegram($matches[0]);
        $bot = $newTg->getMe();
        if ($bot['ok'] == 1 && $redis->hGet('waitToken', $chat_id) == 1) {
            $wh = $newTg->setWebhook(URL);
            if ($wh['ok'] == 1) {
                $redis->hSet('tokens', $chat_id, $matches[0]);
                $redis->hSet('waitToken', $chat_id, 0);
                $telegram->sendMessage($service->genMenu());
            } else {
                $telegram->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => 'Что-то произошло не так, бот не подключён. Уточните токен или отмените с помощью команды /done.'
                ]);
            }

        } elseif ($bot['ok'] == 0) {
            $telegram->sendMessage([
                'chat_id' => $chat_id,
                'text' => "Токен не подошёл. Уточните токен или отмените с помощью команды /done."
            ]);
        }
    }

    if ($text == "/done") {
        $service->cancelWaiting();
        $telegram->sendMessage($service->genMenu());
    }

    if ($text == "/menu") {
        $telegram->sendMessage($service->genMenu());
    }

    if ($text == "/setToken") {
        $service->setTokenMsg();
    }

    if ($text == "/setChannel") {
        $service->setChannelMsg();
    }

    if ($redis->hGet('waitChannel', $chat_id) == 1) {
        $chat = null;
        if (preg_match('/(@(\w+)|t.me\/(\w+))/', $message['text'], $matches)) {
            $chat = '@' . str_ireplace(['@', 't.me/'], ['', ''], $matches[0]);
        } elseif (isset($message['forward_from_chat'])) {
            $chat = $message['forward_from_chat']['id'];
        }
        if (!is_null($chat)) {
            $token = $redis->hGet('tokens', $chat_id);
            $flag = $service->botIsAdmin($chat, $chat_id);
            if ($flag) {
                $chat = $service->getChatId($chat);
                $redis->sRem('channels:' . $token, $chat);
                $redis->sAdd('channels:' . $token, $chat);
                $text = 'Канал подключен, добавьте следующий канал или нажмите команду /done для прекращения добавления каналов.';
            } else {
                $text = 'У бота нет доступа к написанию сообщений в этом канале. Настройте права и попробуйте ещё раз, или отмените добавление канала с помощью команды /done.';
            }

            $telegram->sendMessage([
                'chat_id' => $chat_id,
                'text' => $text
            ]);
        }
    }
}

if (!is_null($telegram->Callback_Data())) {
    $callback = $telegram->Callback_Query();
    $callbackData = explode(":", $callback['data']);
    $chat_id = $callback['from']['id'];
    switch ($callbackData[0]) {
        case 'setChannel':
            $service->setChannelMsg();
            break;
        case 'delChannel':
            if (count($callbackData) == 1) {
                $token = $redis->hGet('tokens', $chat_id);
                $buttons = [];
                $tg = new Telegram($token);
                foreach ($redis->sMembers('channels:' . $token) as $chat) {
                    $title = $tg->getChat(['chat_id' => $chat])['result']['title'];
                    array_push($buttons, ['callback_data' => 'delChannel:' . $chat, 'text' => $title]);
                }
                $buttons = array_chunk($buttons, 2);
                $telegram->editMessageText([
                    'chat_id' => $chat_id,
                    'message_id' => $callback['message']['message_id'],
                    'text' => 'Выберите канал, который хотите убрать из системы.',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => $buttons
                    ])
                ]);
            } else {
                $token = $redis->hGet('tokens', $chat_id);
                $redis->sRem('channels:' . $token, $callbackData[1]);
                //$service->debug($menu);
                $telegram->editMessageText(array_merge($service->genMenu(), ['message_id' => $callback['message']['message_id']]));
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback['id'],
                    'text' => 'Канал удалён из системы'
                ]);
            }
            break;

        case 'setToken':
            $service->setTokenMsg();
            break;
        case 'endCampaign':
            $token = $redis->hGet('tokens', $chat_id);
            $redis->sRem('campaigns', $token);
            $telegram->editMessageText(array_merge($service->genMenu(), ['message_id' => $callback['message']['message_id']]));

            break;
        case 'startCampaign':
            $token = $redis->hGet('tokens', $chat_id);
            $tg = new Telegram($token);
            $flag = 1;
            $chats = [];
            foreach ($redis->sMembers('channels:' . $token) as $chat) {
                $admins = $tg->getChatAdministrators(['chat_id' => $chat]);
                ($service->botIsAdmin($chat, $chat_id)) ? array_push($chats, $chat) : $flag = 0;
            }
            
            if ($flag == 1) {
                
                if ($redis->sIsMember('campaigns', $token)!=1) {
                    $redis->sAdd('campaigns', $token);
                    foreach ($redis->sMembers('channels:' . $token) as $chat) {
                        $tg->sendMessage($service->genPost($chat,$token));
                        ($service->botIsAdmin($chat, $chat_id)) ? array_push($chats, $chat) : $flag = 0;
                    }
                    
                    $telegram->answerCallbackQuery([
                        'callback_query_id' => $callback['id'],
                        'text' => 'Сообщения с объявлением направлены в каналы'
                    ]);

                    
                    $telegram->editMessageText(array_merge($service->genMenu(), ['message_id' => $callback['message']['message_id']]));


                }

            } else {
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback['id'],
                    'text' => 'Бот не добавлен в качестве администратора в один из каналов'
                ]);
            }

            break;
        case 'accept':
            //$service->debug($callback);
            $token=0;
            foreach ($redis->sMembers('campaigns') as $k) if (explode(':', $k)[0] == $callbackData[1]) $token=$k;
            
            if($token!=0){
                $tg=new Telegram($token);
                $tg->answerCallbackQuery([
                    'callback_query_id' => $callback['id'],
                    'text' => 'Спасибо за участие'
                ]);
                //$service->debug($callback);

                $redis->sAdd('members:'.$token,serialize($callback['from']));
                /*foreach($redis->sMembers('members:'.$token) as $member){
                    $service->debug($member);
                }*/

                
            } 
            $tg->editMessageText(array_merge($service->genPost($callback['message']['chat']['id'],$token), ['message_id' => $callback['message']['message_id']]));
            break;
    }
}

$redis->close();