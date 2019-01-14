<?php

include("Telegram.php");
// Set the bot TOKEN
$bot_id = "469108514:AAHqFiWx09JfkTCdNtiwYY3pd7I7-dBMIQQ";
// Instances the class
$telegram = new Telegram($bot_id);
/* If you need to manually take some parameters
*  $result = $telegram->getData();
*  $text = $result["message"] ["text"];
*  $chat_id = $result["message"] ["chat"]["id"];
*/
// Take text and chat_id from the message
$text = $telegram->Text();
$chat_id = $telegram->ChatID();
$redis=new Redis();
$message=$telegram->Message();
$promoChannel="@ArkhamChannel";
$promoChannels=['@ArkhamChannel','@stankocomics'];
$admins=[32512143,174642774];
function redis_error($error) {
    throw new error($error);
}

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
            $redis->connect('127.0.0.1', 6379);
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
if($telegram->Callback_Data()=='accept'){
    $redis->connect('127.0.0.1', 6379);
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

    $redis->close();
    //$telegram->sendMessage(['chat_id'=>32512143,'text'=>print_r($telegram->Callback_Query(),true)]);
}
