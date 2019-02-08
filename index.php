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
        $service->setTokenMsg();
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
                    'text' => 'Что-то произошло не так, бот не подключён. Уточните токен или отмените с помощью команды /cancel.'
                ]);
            }

        } elseif ($bot['ok'] == 0) {
            $telegram->sendMessage([
                'chat_id' => $chat_id,
                'text' => "Токен не подошёл. Уточните токен или отмените с помощью команды /cancel."
            ]);
        }
    }

    if ($text == "/cancel") {
        $service->cancelWaiting();
        $text = "*Отмена.*\r\n\r\nДля добавления или обновления бота нажмите /setToken";
        $telegram->sendMessage([
            'chat_id' => $chat_id,
            'text' => "*Отмена.*\r\n\r\nДля добавления или обновления бота нажмите /setToken, для добавления канала — /setChannel.",
            'parse_mode' => 'Markdown'
        ]);
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

    if($redis->hGet('waitChannel',$chat_id)==1){
        $chat=null;
        if(preg_match('/(@(\w+)|t.me\/(\w+))/', $message['text'], $matches)){
            $chat='@'.str_ireplace(['@','t.me/'],['',''],$matches[0]);
        }elseif(isset($message['forward_from_chat'])){
            $chat=$message['forward_from_chat']['id'];
        }
        if(!is_null($chat)){
            $token=$redis->hGet('tokens',$chat_id);
            $flag=$service->botIsAdmin($chat,$chat_id);
            if($flag){
                $chat=$service->getChatId($chat);
                $redis->sRem('channels:'.$token,$chat);
                $redis->sAdd('channels:'.$token,$chat);
                $text='Канал подключен, добавьте следующий канал или нажмите команду /cancel для прекращения добавления каналов.';
            }else{
                $text='У бота нет доступа к написанию сообщений в этом канале. Настройте права и попробуйте ещё раз, или отмените добавление канала с помощью команды /cancel.';
            }

            $telegram->sendMessage([
                'chat_id'=>$chat_id,
                'text'=>$text
            ]);
        }
    }
} elseif (!is_null($telegram->Callback_Data())) {
    $callback = $telegram->Callback_Query();
    switch ($callback['data']) {
        case 'setChannel':
            $service->setChannelMsg();
            break;
        case 'delChannel':
            $token=$redis->hGet('tokens',$chat_id);
            $service->debug($token);
            $buttons=[];
            foreach($redis->sMembers('channels:'.$token) as $chat){
                array_push($buttons, [['callback_data' =>'delChannel:'.$chat, 'text' => $telegram->getChat(['chat_id'=>$chat])['result']['title']]]);
            }
            $service->debug($buttons);
            $buttons=array_chunk($buttons,2);
            $telegram->editMessageText([
                'chat_id'=>$callback['from']['id'],
                'message_id'=>$callback['message']['message_id'],
                'text'=>'Выберите канал, который хотите убрать из системы.',
                'reply_markup' => json_encode([
                    'inline_keyboard' => $buttons
                ])
                
            ]);

            break;
        case 'setToken':
            $service->setTokenMsg();
            break;
    }
}

$redis->close();