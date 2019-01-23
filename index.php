<?php

include("Telegram.php");
$bot_id = "730506161:AAEUhC1R9dA829hqHfhjTQ7eNl1a--sYE2s";
$telegram = new Telegram($bot_id);

$redis=new Redis();

$text = $telegram->Text();
$chat_id = $telegram->ChatID();
$message=$telegram->Message();

$redis->connect('127.0.0.1', 6379);

$promoChannel="@ArkhamChannel";
$promoChannels=['@ArkhamChannel','@stankocomics'];
$admins=[32512143,174642774];

function redis_error($error) {
    throw new error($error);
}

if(!is_null($text) && $chat_id>0){
    if ($text == "/start") {
        $telegram->sendMessage([
            'chat_id'=>$chat_id,
            'text'=>"*Для начала работы создайте своего бота и укажите его токен.*\r\n\r\nЧтобы создать своего бота, перейдите к боту @BotFather и отправьте команду `/newbot`, после чего вам будет предложено ввести имя и юзернейм бота. В ответ BotFather пришлёт сообщение с токеном вашего бота. Вставьте или перешлите токен сюда.",
            'parse_mode'=>'Markdown'
        ]);
        $redis->hSet('waitToken',$chat_id,1);
    }
    
    if ($text == "/addBot") {
        $telegram->sendMessage([
            'chat_id'=>$chat_id,
            'text'=>"*Cоздайте своего бота и укажите его токен.*\r\n\r\nЧтобы создать своего бота, перейдите к боту @BotFather и отправьте команду `/newbot`, после чего вам будет предложено ввести имя и юзернейм бота. В ответ BotFather пришлёт сообщение с токеном вашего бота. Вставьте или перешлите токен сюда.",
            'parse_mode'=>'Markdown'
        ]);
        $redis->hSet('waitToken',$chat_id,1);
    }

    if(preg_match('/\d+:.+/', $text, $matches)){
        $newTg=new Telegram($matches[0]);
        $bot=$newTg->getMe();
        if($bot['ok']==1 && $redis->hGet('waitToken',$chat_id)==1){
            $telegram->sendMessage([
                'chat_id'=>$chat_id,
                'text'=>"Бот @".$bot['result']['username']." добавлен для проведения конкурса."
            ]);
            $redis->sAdd('tokens:'.$chat_id,$matches[0]);
            $redis->hSet('waitToken',$chat_id,0);
        }elseif($bot['ok']==0){
            $telegram->sendMessage([
                'chat_id'=>$chat_id,
                'text'=>"Токен не подошёл. Уточните токен или отмените с помощью команды /cancel."
            ]);
        }
    }

    if ($text == "/cancel") {
        $redis->hSet('waitToken',$chat_id,0);
        $text="Добавление бота отменено.\r\nЧтобы добавить нового бота нажмите команду /newBot.";
        if($redis->sCard('tokens:'.$chat_id)>0) $text.="\r\nЧтобы начать новый розыгрыш призов нажмите команду /newСampaign.";
        $telegram->sendMessage([
            'chat_id'=>$chat_id,
            'text'=>$text
        ]);
    }

    if ($text == "/newCampaign") {
        if($redis->sCard('tokens:'.$chat_id)!=0){
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
    }
}elseif(!is_null($telegram->Callback_Data())){
    $callback=$telegram->Callback_Query();
    
    $callbackData=explode(":",$callback['data']);
    switch($callbackData[0]){
        case 'campaign':
            $redis->hSet($callback['data'],'start',time());
            
        break;
    }
    /*$redis->connect('127.0.0.1', 6379);
    $callback=$telegram->Callback_Query();
    $text=(in_array(serialize($callback['from']),$redis->sMembers('promo')))?"Вы уже участвуете в розыгрыше🤷‍♂️ ":"Спасибо за участие. Следите за результатами в нашем канале 😉 ";
    $telegram->answerCallbackQuery([
        'callback_query_id'=>$callback['id'],
        'text'=>$text,
        'show_alert'=>true
    ]);
    $redis->sAdd('promo',serialize($callback['from']));

    $message=$telegram->Callback_Message();
    $text=explode("😊",$message['text']);
    $telegram->editMessageText([
        'chat_id'=>$message['chat']['id'],
        'message_id'=>$message['message_id'],
        'text'=>$text[0]."😊\r\n Участвует: ".count($redis->sMembers('promo')),
        'reply_markup'=>json_encode([
            'inline_keyboard'=>[[[
                'text'=>'Я участвую!',
                'callback_data'=>'accept'
            ]]]
        ])
    ]);
*/
    //$redis->close();
    //$telegram->sendMessage(['chat_id'=>32512143,'text'=>print_r($telegram->Callback_Query(),true)]);
}



$redis->close();


// Check if the text is a command
if(!is_null($text) && in_array($chat_id,$admins)){
    if (!$telegram->messageFromGroup()) {
        if ($text == "/newpromo") {
          foreach ($promoChannels as $value) {
            $telegram->sendMessage([
                'chat_id'=>$value,
                'text'=>'Нажимая кнопку ниже пользователь подтверждает, что он старше 18 лет, живёт на территории стран СНГ и подписан на каналы @StankoComics и @ArkhamChannel.',
                'reply_markup'=>json_encode([
                    'inline_keyboard'=>[[[
                        'text'=>'Я участвую!',
                        'callback_data'=>'accept'
                    ]]]
                ])
            ]);
          }
        }

        if ($text == "/showMembers") {
            $members=$redis->sGetMembers('promo');
            $redis->close();
            $text="Количество участников: ".count($members)."\r\n Список участников:\r\n";
            $i=0;
            $j=0;
            foreach($members as $member){
                $a=unserialize($member);
                $text.=$a['first_name']." ".$a['last_name']."(@".$a['username'].")\r\n";
                $i++;
                if($i==30){
                    $j++;
                    $telegram->sendMessage([
                        'chat_id'=>$chat_id,
                        'text'=>"Страница: ".$j."\r\n".$text
                    ]);
                    $text="Количество участников: ".count($members)."\r\n Список участников:\r\n";
                    $i=0;
                }
            }
            $j++;
            $telegram->sendMessage([
                'chat_id'=>$chat_id,
                'text'=>"Страница: ".$j."\r\n".$text
            ]);
        }

        if ($text == "/erase") {
            $redis->connect('127.0.0.1', 6379);
            $redis->delete('promo');
            $telegram->sendMessage([
                'chat_id'=>$chat_id,
                'text'=>"Список участников очищен."
            ]);
            $redis->close();
        }

        if ($text == "/showWinner") {
            $flag=false;
            $redis->connect('127.0.0.1', 6379);
            $members=$redis->sGetMembers('promo');
            $winner=unserialize($members[array_rand($members)]);
            $i=0;
            while (!$flag){
                $winner=unserialize($members[array_rand($members)]);
                $flag=true;
                foreach ($promoChannels as $key => $value) {
                  $status=$telegram->getChatMember(['chat_id'=>$value,'user_id'=>$winner['id']])['result']['status'];
                  if($status!='member') $flag=false;
                }

                if($flag){
                  foreach ($promoChannels as $key => $value) {
                    $telegram->sendMessage([
                        'chat_id'=>$value,
                        'text'=>" 🏆Победитель🏆 \r\n".$winner['first_name']." ".$winner['last_name']."(".$winner['username'].")"
                    ]);
                  }
                    $telegram->sendMessage([
                        'chat_id'=>$chat_id,
                        'text'=>" 🏆Победитель🏆 \r\n".print_r($winner,true)."\r\nВ канале?\r\n".$status
                    ]);
                }
                $i++;
                if($i==100)$flag=true;
            }
            $redis->close();
        }
      }
}


