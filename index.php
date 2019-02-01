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
                $telegram->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => print_r($matches,true)
                ]);

            if ($wh['ok'] == 1) {
                $redis->hSet('tokens', $chat_id, $matches[0]);
                $redis->hSet('waitToken', $chat_id, 0);
                $telegram->sendMessage($service->genMenu());
            } else {
                $newTg->sendMessage([
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
        $redis->hSet('waitToken', $chat_id, 0);
        $text = "*Отмена.*\r\n\r\nДля добавления или обновления бота нажмите /setToken";
        $telegram->sendMessage([
            'chat_id' => $chat_id,
            'text' => "*Отмена.*\r\n\r\nДля добавления или обновления бота нажмите /setToken, для добавления канала — /setChannel.",
            'parse_mode' => 'Markdown'
        ]);
    }

    if ($text == "/menu") {
       $service->genMenu();
    }

    if ($text == "/setToken") {
        $service->setTokenMsg();
    }

    if ($text == "/setChannel") {
        $service->setChannelMsg();
    }

    /*if ($text == "/newCampaign") {
        if($redis->hGet('tokens',$chat_id)!=0){
            $a=[];
            foreach($redis->sMembers('tokens:'.$chat_id) as $i){
                $tg=new Telegram($i);
                $bot=$tg->getMe();
                if($bot['ok']==1) $a[$i]=$bot['result']['username'];
            }
            if(count($a)>0){
                $buttons=[];
                foreach($a as $i=>$v){
                    array_push($buttons,['text'=>$v,'callback_data'=>'campaign:'.$i]);
                }
                $telegram->sendMessage([
                    'chat_id'=>$chat_id,
                    'text'=>"Выберите бота для проведения конкурса.",
                    'reply_markup'=>json_encode([
                        'inline_keyboard'=>[$buttons]
                    ])
                ]);
            }else{
                $telegram->sendMessage([
                    'chat_id'=>$chat_id,
                    'text'=>"*У вас нет подключённых ботов.*\r\n\r\nЧтобы создать своего бота, перейдите к боту @BotFather и отправьте команду `/newbot`, после чего вам будет предложено ввести имя и юзернейм бота. В ответ BotFather пришлёт сообщение с токеном вашего бота. Вставьте или перешлите токен сюда.",
                    'parse_mode'=>'Markdown'
                ]);
                $redis->hSet('waitToken',$chat_id,1);
            }
        }else{
            $telegram->sendMessage([
                'chat_id'=>$chat_id,
                'text'=>"*У вас нет подключённых ботов.*\r\n\r\nЧтобы создать своего бота, перейдите к боту @BotFather и отправьте команду `/newbot`, после чего вам будет предложено ввести имя и юзернейм бота. В ответ BotFather пришлёт сообщение с токеном вашего бота. Вставьте или перешлите токен сюда.",
                'parse_mode'=>'Markdown'
            ]);
            $redis->hSet('waitToken',$chat_id,1);
        }
    }*/
} elseif (!is_null($telegram->Callback_Data())) {
    $callback = $telegram->Callback_Query();
    switch ($callback['data']) {
        case 'setChannel':
            $service->setChannelMsg();
            break;
        case 'setToken':
            $service->setTokenMsg();
            break;
    }
}

$redis->close();