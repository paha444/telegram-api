<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;

use Illuminate\Support\Facades\App;

use Illuminate\Console\Command;
use Workerman\Worker;

use Illuminate\Database\Query\JoinClause;

use Illuminate\Support\Facades\Cookie;

use DB;

use App\Models\User;

use App\Http\Controllers\User\BalanceController;
use App\Http\Controllers\User\UserController;
use App\Http\Controllers\User\PublicationsController;
use App\Http\Controllers\User\OrdersController;

use Crypter;
use Log;

class TelegramController extends Controller
{

    public static function getTelegramUserData() {
      if (isset($_COOKIE['tg_user'])) {
        $auth_data_json = urldecode($_COOKIE['tg_user']);
        $auth_data = json_decode($auth_data_json, true);
        return $auth_data;
      }
      return false;
    }

    private function checkTelegramAuthorization($request) {
        
      $auth_data = $request->all();
        
      $check_hash = $auth_data['hash'];
      unset($auth_data['hash']);
      $data_check_arr = [];
      
      
      foreach ($auth_data as $key => $value) {
        $data_check_arr[] = $key . '=' . $value;
      }
      sort($data_check_arr);
      $data_check_string = implode("\n", $data_check_arr);
      $secret_key = hash('sha256', env('TELEGRAM_TOKEN'), true);
      $hash = hash_hmac('sha256', $data_check_string, $secret_key);
      if (strcmp($hash, $check_hash) !== 0) {
        throw new Exception('Data is NOT from Telegram');
      }
      if ((time() - $auth_data['auth_date']) > 86400) {
        throw new Exception('Data is outdated');
      }
      return $auth_data;
    }
    
    private function saveTelegramUserData($auth_data) {
        
        $checkUser = DB::table('users')
        ->where('telegram_id', $auth_data['id'])
        ->first(); 
        
        if($checkUser){
            Auth::loginUsingId($checkUser->id);
        }else{
            $id = DB::table('users')->insertGetId([
                'telegram_id'=>$auth_data['id'],
                'name'=>$auth_data['first_name'],
                'password'=>Hash::make(10),
                'email'=>Str::random(10).'@t.me'
            ]);
            Auth::loginUsingId($id);
        }         
      
      $auth_data_json = json_encode($auth_data);

      Cookie::queue('tg_user', $auth_data_json, env('SESSION_LIFETIME', 120));
    }

    
    public function check_auth(Request $request)
    {        
        
        try {
          $auth_data = $this->checkTelegramAuthorization($request);
          $this->saveTelegramUserData($auth_data);
        } catch (Exception $e) {
          die ($e->getMessage());
        }
        
        return redirect()->route('login');

    }


    public static function getChannelInfo($chat_id)
    {     

        $params = [
            'chat_id' => $chat_id
        ];                   
                   
        $result = self::post($params,'getChat');
        return $result;    
    
    }

    public static function getChatAdministrators($chat_id)
    {     
        
        $user = Auth::user();
        
        $params = [
            'chat_id' => '@'.str_replace('https://t.me/','', $chat_id)
        ];                   
                   
        $Administrators = self::post($params,'getChatAdministrators');
        
        $result = false;
        
        if($Administrators->ok && $Administrators->result){
            foreach($Administrators->result as $key=>$item){
                
                if($item->user->id == $user->telegram_id && $item->status=='creator'){
                    $result = true;
                    break;
                }
                
            }
        }
        
        return $result;    
            
    }

    public static function autoposting()
    {
         date_default_timezone_set('Europe/Kiev');
         
         $published= date('Y-m-d H:i').':00';
         echo 'time on server :'.$published;
         
         echo '<br><br>';

         self::postingPublications();
         self::postingOrders();
    
    }


    public static function postingOrders()
    {
         $published= date('Y-m-d H:i').':00';

         $orders = DB::table('orders')
             ->join('channels', 'orders.channel_id', '=', 'channels.id')
             ->join('tariffs', 'orders.tariff_id', '=', 'tariffs.id')
             ->where(['orders.status' => 1,'orders.published'=>$published])
             //->where(['orders.status' => 1])
             ->select('orders.*','channels.link as channel_link','channels.user_id as channel_user_id','tariffs.price as price')
         ->get();       
         
         //print_r($orders); die;
         
        if($orders){
            
            foreach($orders as $key=>$order){

            $UserOrder = User::find($order->user_id);
            $OwnerChannel = User::find($order->channel_user_id);

            //Проверка баланса у пользователя
            if(BalanceController::checkBalance($UserOrder,$order->price)){

                $images = OrdersController::getFiles($order->id,['jpeg','jpg','png','gif']);
                $files = OrdersController::getFiles($order->id,['doc','docx','pdf','txt','xls','xlsx']);
                
                $chat_id = str_replace('https://t.me/','',$order->channel_link);
                
                    $sendTelegram = self::createPost($chat_id,$order,$images,$files);

                    if(isset($sendTelegram) && $sendTelegram->ok==1):
                        
                        if(is_array($sendTelegram->result)){
                            $message_ids = [];
                            foreach($sendTelegram->result as $message) $message_ids[]=$message->message_id;
                            $message_id = implode(',',$message_ids);
                        }else{
                            $message_id = $sendTelegram->result->message_id;
                        }

                        OrdersController::addPublicationsTelegramDB($order->id,$message_id);
                        
                        //Списание оплаты за пост
                        BalanceController::MinBalance($UserOrder,$order->price);
                        //Пополнение баланса владельца канала
                        BalanceController::AddBalance($OwnerChannel,$order->price);
                        
                        UserController::addReport($UserOrder,'Заявка ID-'.$order->id.' опубликована. Списано -'.$order->price.'грн.');
                                                                 
                    else:
                        //print_r($params);
                        print_r($sendTelegram);
                    endif;
                    
//////////////////////////                
           
            }else{

                UserController::addReport($UserOrder,'Недостаточно средств для публикации. Заявка ID-'.$order->id.' не опубликована.');

            }
            
            
            }
            
            
        }

    }
    

    public static function postingPublications()
    {

         $published= date('Y-m-d H:i').':00';

         $publications = DB::table('publications')
            ->where(['status' => 1])
            
            ->where(function ($query) use ($published) {
                $query->where('date_published', $published)
                      ->orWhere('date_repeat', $published);
            })            
          
         ->get();       
         
        foreach($publications as $key=>$publication){
            

            $UserPublication = User::find($publication->user_id);
    
            //Проверка тарифа и функции отложенного постинга
            if($UserPublication->getUserPackage && $UserPublication->PackageInfo()->delayed_posting){
                
                $images = PublicationsController::getFiles($publication->id,['jpeg','jpg','png','gif']);
                $files = PublicationsController::getFiles($publication->id,['doc','docx','pdf','txt','xls','xlsx']);
                                                      
                $channels = DB::table('channels')->whereIn('id',json_decode($publication->channels_id))->get();
            
                if($channels):  
                    
                    foreach($channels as $key=>$channel):
                        
                        $chat_id = str_replace('https://t.me/','',$channel->link);
                        
                        
                        $sendTelegram = self::createPost($chat_id,$publication,$images,$files);
                        
                        if(isset($sendTelegram) && $sendTelegram->ok==1):
                            
                            if(is_array($sendTelegram->result)){
                                $message_ids = [];
                                foreach($sendTelegram->result as $message) $message_ids[]=$message->message_id;
                                $message_id = implode(',',$message_ids);
                            }else{
                                $message_id = $sendTelegram->result->message_id;
                            }
    
                            PublicationsController::addPublicationsTelegramDB($publication->id,$message_id);
                            
                            UserController::addReport($UserPublication,'Пост ID-'.$publication->id.' опубликован.');
                                                                     
                      
                        else:
                            //print_r($params);
                            print_r($sendTelegram);
                            Log::debug('chatid - '.$chat_id.' PostID - '.$publication->id.' :'.json_encode($sendTelegram));
                        endif;
                        

                     endforeach; 
                      
                 endif; 
               
               }else{
               
                 //Если нет тарифа или отключен отложенный постинг отключаем публикацию
                 PublicationsController::setStatus($publication->id,2);
                 UserController::addReport($UserPublication,'Ваш тариф не найден или в вашем тарифе нет функции отложенного постинга. Пост ID-'.$publication->id.' не опубликован.');
                 
                 
               }
         
         }
        
    }
    
    
    
    public static function createPost($chat_id,$publication,$images,$files)
    {

                    switch ($publication->type) {
                        case 1:
                           
                           //Изображение и описание
                            
                           $params = array(
                                'chat_id' => '@'.$chat_id,
                                'photo' => (isset($images[0]))? env('APP_URL_DEV').'/'.$images[0]->path.'/'. $images[0]->filename : '', // режим отображения сообщения HTML (не все HTML теги работают)
                                'caption'=> $publication->message,
                                'parse_mode' => 'HTML',
                           ); 
                           
                           $sendTelegram = self::post($params,'sendPhoto');     

                           break;
                        case 2:
                            
                          //Группа изображений и описание  
                            
                            $media = [];
                            $media_img = [];
                            foreach($images as $key=>$image){
                                if($key==0):
                                    array_push($media,['type' => 'photo', 'media' => 'attach://image_'.$key, 'caption'=>$publication->message, 'parse_mode' => 'HTML']);
                                else:
                                    array_push($media,['type' => 'photo', 'media' => 'attach://image_'.$key]);
                                endif;
                                $media_img['image_'.$key] = curl_file_create($image->path.'/'. $image->filename);
                            } 
                            
                            $params = [
                                'chat_id' => '@'.$chat_id,
                                'media' => json_encode($media,JSON_UNESCAPED_UNICODE),
                            ];                             
                            
                            $params = array_merge($params, $media_img);
                            

                            $sendTelegram = self::post_media($params,'sendMediaGroup');     

                            break;
                        case 3:
                            
                        //Изображение и кнопки(ссылки)    
                        
                        $replyMarkup = [];
                        
                            if($publication->links){
                                
                                $links = json_decode($publication->links);
                                $buttons=[];
                                foreach($links->links as $key=>$value){
                                    $buttons[]=['text'=>$links->links_text[$key],"url"=>$value];
                                }           
                                $replyMarkup = json_encode(["inline_keyboard"=>[$buttons]],JSON_UNESCAPED_UNICODE);                            

                                $params = array(
                                    'chat_id' => '@'.$chat_id,
                                    'photo' => (isset($images[0]))? env('APP_URL_DEV').'/'.$images[0]->path.'/'. $images[0]->filename : '', // режим отображения сообщения HTML (не все HTML теги работают)
                                    'caption'=> $publication->message,
                                    'parse_mode' => 'HTML',
                                    
                                    "reply_markup"=>$replyMarkup,
                                );                            
                                
                                $sendTelegram = self::post($params,'sendPhoto');   
                            
                            }
                            
                            break;
                        case 4:
                            
                            // Опрос и описание
                            if($publication->question){
                                $question = json_decode($publication->question);          

                                $params = array(
                                    'chat_id' => '@'.$chat_id,
                                    'question'=> $question->question,
                                    'options' => $question->variant,
                                );    
                                
                                if($question->several==1) $params = array_merge($params, ['allows_multiple_answers'=>1]);
                                if($question->quiz==1):
                                    $params = array_merge($params, ['type'=>'quiz']);
                                    $params = array_merge($params, ['correct_option_id'=>0]);
                                endif;
    
                                $sendTelegram = self::post($params,'sendPoll');   
                                
                            }
                            
                            break;
    
                        case 5:
                        
                        //Простое сообщение
                        
                           $params = array(
                                'chat_id' => '@'.$chat_id,
                                'text' => $publication->message.' <a href="'.$publication->link.'">'.$publication->link.'</a>', // текст сообщения
                                'parse_mode' => 'HTML', // режим отображения сообщения HTML (не все HTML теги работают)
                           );
                                           
                            $sendTelegram = self::post($params,'sendMessage');                        
                        
                        break;
                        
                        case 6:
                        
                        //Документ
                            foreach($files as $key=>$file){
                            
                                $params = [
                                    'chat_id' => '@'.$chat_id,
                                    //'caption' => $publication->message,
                                    'document' => curl_file_create($file->path.'/'. $file->filename)                                
                                ];                             
                                
                                $sendTelegram = self::post_media($params,'sendDocument'); 
                            
                            } 
                                                  
                        break;
                    }
                    
               return $sendTelegram;     
        
    }    
    
 
    public static function autopostingdelete()
    {   

         date_default_timezone_set('Europe/Kiev');
         $date= date('Y-m-d H:i').':00';
         echo 'time on server :'.$date;
         echo '<br><br>';
    
         self::deletePublications();    
         //self::deleteOrders();    
    }
    
    
    public static function deleteOrders()
    {   

         $orders = DB::table('orders')
             ->join('channels', 'orders.channel_id', '=', 'channels.id')
             ->rightJoin('orders_telegram', 'orders.id', '=', 'orders_telegram.order_id')
             //->join('tariffs', 'orders.tariff_id', '=', 'tariffs.id')
             //->where(['orders.status' => 1,'orders.published'=>$published])
             ->where(['orders.status' => 1])
             ->select('orders.*','channels.link as channel_link','orders_telegram.message_id')
         ->get(); 
         
             foreach($orders as $key=>$order){
                
                $chat_id = str_replace('https://t.me/','',$order->channel_link);
                
                $params = array(
                    'chat_id' => '@'.$chat_id,
                    'message_ids' => explode(',',$order->message_id), // текст сообщения
                );    
            
                $sendTelegram = self::post($params,'deleteMessages');  
                        
               DB::delete('delete from orders_telegram where id = ?',[$order->id]);
        
            }
    
    }    
    
    
    public static function deletePublications()
    {   
         
         $date= date('Y-m-d H:i').':00';        
        
         $publications = DB::table('publications')
         
         ->rightJoin('publications_telegram', 'publications.id', '=', 'publications_telegram.publication_id')
         ->where(['publications.status' => 1,'publications.date_delete'=>$date])
         //->where(['publications.status' => 1])
         ->select('publications_telegram.*','publications.channels_id')
         ->get();       
         

             foreach($publications as $key=>$publication){
                
                if($publication->channels_id):
                    $channels = DB::table('channels')->whereIn('id',json_decode($publication->channels_id))->get();
    
                    if($channels):  
                        
                        foreach($channels as $key=>$channel){
                            
                            $chat_id = str_replace('https://t.me/','',$channel->link);
                            
                            $params = array(
                                'chat_id' => '@'.$chat_id,
                                'message_ids' => explode(',',$publication->message_id), // текст сообщения
                            );    
                        
                            $sendTelegram = self::post($params,'deleteMessages');  
                        
                        }
                    
                    endif;
               endif; 
               
               DB::delete('delete from publications_telegram where id = ?',[$publication->id]);
        
            }
    
    }
    

    public static function post_media($params,$method)
    {
    
        $curl = curl_init('https://api.telegram.org/bot'. env('TELEGRAM_TOKEN') .'/'.$method);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $params);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        $result = curl_exec($curl);
        curl_close($curl);    
        
        return json_decode($result);
    }

    
    public static function post($params,$method)
    {

        $post = json_encode($params);  
          
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, 'https://api.telegram.org/bot'.env('TELEGRAM_TOKEN').'/'.$method); // адрес вызова api функции телеграм
    
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        
        curl_setopt($curl, CURLOPT_POST, true); // отправка методом POST
        curl_setopt($curl, CURLOPT_TIMEOUT, 10); // время выполнения запроса
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION , true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $post); // параметры запроса
        $result = curl_exec($curl); // запрос к api
        curl_close($curl);
          
        return json_decode($result);


    }
    
}
