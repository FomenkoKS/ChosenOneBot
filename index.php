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
                $text='Канал подключен, добавьте следующий канал или нажмите команду /done для прекращения добавления каналов.';
            }else{
                $text='У бота нет доступа к написанию сообщений в этом канале. Настройте права и попробуйте ещё раз, или отмените добавление канала с помощью команды /done.';
            }

            $telegram->sendMessage([
                'chat_id'=>$chat_id,
                'text'=>$text
            ]);
        }
    }
} elseif (!is_null($telegram->Callback_Data())) {
    $callback = $telegram->Callback_Query();
    $callbackData=explode(":",$callback['data']);
    switch ($callbackData[0]) {
        case 'setChannel':
            $service->setChannelMsg();
            break;
        case 'delChannel':
            if(count($callbackData)==1){
                $token=$redis->hGet('tokens',$callback['from']['id']);
                $buttons=[];
                $tg=new Telegram($token);
                foreach($redis->sMembers('channels:'.$token) as $chat){
                    $title=$tg->getChat(['chat_id'=>$chat])['result']['title'];
                    array_push($buttons, ['callback_data' =>'delChannel:'.$chat, 'text' => $title]);
                }
                $buttons=array_chunk($buttons,2);
                $telegram->editMessageText([
                    'chat_id'=>$callback['from']['id'],
                    'message_id'=>$callback['message']['message_id'],
                    'text'=>'Выберите канал, который хотите убрать из системы.',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => $buttons
                    ])
                ]);
            }else{
                $token=$redis->hGet('tokens',$callback['from']['id']);
                $redis->sRem('channels:'.$token, $callbackData[1]);
                $service->debug($menu);
                $telegram->editMessageText(array_merge($service->genMenu(),['message_id'=>$callback['message']['message_id']]));
                $telegram->answerCallbackQuery([
                    'callback_query_id'=>$callback['id'],
                    'text'=>'Канал удалён из системы'
                ]);
            }
            break;
        case 'setToken':            
            $service->setTokenMsg();
            break;
    }
}

$redis->close();