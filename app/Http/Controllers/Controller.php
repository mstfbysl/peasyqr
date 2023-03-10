<?php

namespace App\Http\Controllers;

use App\Restorant;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Str;
use Image;
use Carbon\Carbon;
use Akaunting\Module\Facade as Module;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    /**
     * @param {String} folder
     * @param {Object} laravel_image_resource, the resource
     * @param {Array} versinos
     */
    public function saveImageVersions($folder, $laravel_image_resource, $versions,$return_full_url=false)
    {
        //Make UUID
        $uuid = Str::uuid()->toString();
       

        //If tinypng is set
        if(Module::has('tinypng')&&config('tinypng.enabled',false)){
            //Go with tiny png

            //Step 1 - upload file locally
            $path = $laravel_image_resource->store(null,'public_uploads');
            $url=config('app.url')."/uploads/restorants/".$path;

            //Build the post
            $dataToSend=[
                "source"=>[
                    "url"=>$url
                ]
            ];
            //Amazon S3
            if(strlen(config('tinypng.aws_access_key_id',"")>5)){
                $dataToSend['store']=array(
                    'service' => 's3',
                    'aws_access_key_id' => config('tinypng.aws_access_key_id',""),
                    'aws_secret_access_key' => config('tinypng.aws_secret_access_key',""),
                    'region' => config('tinypng.region',""),
                    'headers' =>  array(
                      'Cache-Control' => 'public, max-age=31536000',
                   ),
                    'path' => config('tinypng.bucket',"").'/'.$uuid.'.jpg',
                );
            }else if(strlen(config('tinypng.gcp_access_token',"")>5)){
                //Google Cloud
                $dataToSend['store']=array(
                    'service' => 'gcs',
                    'gcp_access_token' => config('tinypng.gcp_access_token',""),
                    'headers' => 
                   (object) array(
                      'Cache-Control' => 'public, max-age=31536000',
                   ),
                    'path' => config('tinypng.gcp_path',"").'/'.$uuid.'.jpg'
                );
            }

            $authCode=base64_encode("api:".config('tinypng.api_key'));
            $response = Http::withHeaders([
                'Authorization' => 'Basic '.$authCode,
                'Content-Type' => 'application/json'
            ])
            ->post('https://api.tinify.com/shrink',$dataToSend);

            //Delete the original file
            //Storage::disk('public_uploads')->delete($path);
            //dd('After submit');

            if($response->successful()){
                $url = $response->json()['output']['url'];
                $contents = file_get_contents($url);
                $name = substr($url, strrpos($url, '/') + 1);
                //dd( $url );
                Storage::disk('public_uploads')->put($name, $contents);
                return $url=config('app.url')."/uploads/restorants/".$name;
            }else{
                //dd($response->getBody()->getContents());
                abort(500,"There is problem with tinyPNG");
            }
        }else{
            //Regular upload
            

            //Make the versions
            foreach ($versions as $key => $version) {
                $ext="jpg";
                if(isset($version['type'])){
                    $ext=$version['type'];
                }

                //Save location
               $saveLocation=public_path($folder).$uuid.'_'.$version['name'].'.'.'jpg';
               if(strlen(config('settings.image_store_path'.''))>3){
                $saveLocation=config('settings.image_store_path'.'').$folder.$uuid.'_'.$version['name'].'.'.'jpg';
               }

                if (isset($version['w']) && isset($version['h'])) {
                    $img = Image::make($laravel_image_resource->getRealPath())->fit($version['w'], $version['h']);
                    $img->save($saveLocation,100,$ext);
                } else {
                    //Original image
                    $img = Image::make($laravel_image_resource->getRealPath());
                    $img->save($saveLocation,100,$ext);
                }
            }

            if($return_full_url){
                return config('app.url')."/".$folder.$uuid.'_'.$version['name'].'.'.'jpg';
            }else{
                return $uuid;
            }

            
        }

        
    }

    private function withinArea($point, $polygon, $n)
    {
        if ($polygon[0] != $polygon[$n - 1]) {
            $polygon[$n] = $polygon[0];
        }
        $j = 0;
        $oddNodes = false;
        $x = $point->lng;
        $y = $point->lat;
        for ($i = 0; $i < $n; $i++) {
            $j++;
            if ($j == $n) {
                $j = 0;
            }
            if ((($polygon[$i]->lat < $y) && ($polygon[$j]->lat >= $y)) || (($polygon[$j]->lat < $y) && ($polygon[$i]->lat >= $y))) {
                if ($polygon[$i]->lng + ($y - $polygon[$i]->lat) / ($polygon[$j]->lat - $polygon[$i]->lat) * ($polygon[$j]->lng - $polygon[$i]->lng) < $x) {
                    $oddNodes = ! $oddNodes;
                }
            }
        }

        return $oddNodes;
    }

    public function calculateDistance($latitude1, $longitude1, $latitude2, $longitude2, $unit)
    {
        $theta = $longitude1 - $longitude2;
        $distance = (sin(deg2rad($latitude1)) * sin(deg2rad($latitude2))) + (cos(deg2rad($latitude1)) * cos(deg2rad($latitude2)) * cos(deg2rad($theta)));
        $distance = acos($distance);
        $distance = rad2deg($distance);
        $distance = $distance * 60 * 1.1515;
        switch ($unit) {
          case 'Mi':
            break;
          case 'K':
            $distance = $distance * 1.609344;
        }

        return round($distance, 2);
    }

    public function getAccessibleAddresses($restaurant, $addressesRaw)
    {
        $addresses = [];
        $polygon = json_decode(json_encode($restaurant->radius));
        $numItems = $restaurant->radius ? count($restaurant->radius) : 0;

        if ($addressesRaw) {
            foreach ($addressesRaw as $address) {
                $point = json_decode('{"lat": '.$address->lat.', "lng":'.$address->lng.'}');

                if (! array_key_exists($address->id, $addresses)) {
                    $new_obj = (object) [];
                    $new_obj->id = $address->id;
                    $new_obj->address = $address->address;

                    if (! empty($polygon)) {
                        if (isset($polygon[0]) && $this->withinArea($point, $polygon, $numItems)) {
                            $new_obj->inRadius = true;
                        } else {
                            $new_obj->inRadius = false;
                        }
                    } else {
                        $new_obj->inRadius = true;
                    }

                    $distance = floatval(round($this->calculateDistance($address->lat, $address->lng, $restaurant->lat, $restaurant->lng,config('settings.unit','K'))));

                    $rangeFound = false;
                    if (config('settings.enable_cost_per_distance')&&config('settings.enable_cost_per_range')) {
                        //Range based pricing

                        //Find the range
                        $ranges = [];

                        //Put the ranges
                        $ranges[0] = explode('-', config('settings.range_one'));
                        $ranges[1] = explode('-', config('settings.range_two'));
                        $ranges[2] = explode('-', config('settings.range_three'));
                        $ranges[3] = explode('-', config('settings.range_four'));
                        $ranges[4] = explode('-', config('settings.range_five'));

                        //Put the prices
                        $ranges[0][2] = floatval(config('settings.range_one_price'));
                        $ranges[1][2] = floatval(config('settings.range_two_price'));
                        $ranges[2][2] = floatval(config('settings.range_three_price'));
                        $ranges[3][2] = floatval(config('settings.range_four_price'));
                        $ranges[4][2] = floatval(config('settings.range_five_price'));

                        
                        //Find the range
                        foreach ($ranges as $key => $range) {
                            if (floatval($range[0]) <= $distance && floatval($range[1]) >= $distance) {
                                $rangeFound = true;
                                $new_obj->range=$range;
                                $new_obj->cost_per_km = floatval($range[2]);
                                $new_obj->cost_total = floatval($range[2]);
                            }
                        }
                        
                    }

                    if (! $rangeFound) {
                        if (config('settings.enable_cost_per_distance') && config('settings.cost_per_kilometer')) {
                            $new_obj->distance = floor($distance);
                            $new_obj->real_cost_m = floatval(config('settings.cost_per_kilometer'));
                            $new_obj->cost_per_km = floor($distance) * floatval(config('settings.cost_per_kilometer'));
                            $new_obj->cost_total = floor($distance)  * floatval(config('settings.cost_per_kilometer'));

                        } else {
                            //Use the static price for delivery
                            $new_obj->cost_per_km = config('global.delivery');
                            $new_obj->cost_total = config('global.delivery');
                        }
                    }

                    if($restaurant->free_deliver==1){
                        $new_obj->cost_per_km = 0;
                        $new_obj->cost_total = 0;
                    }

                    $new_obj->rangeFound=$rangeFound;

                    $addresses[$address->id] = (object) $new_obj;
                }
            }
        }
        return $addresses;
    }

    public function getRestaurant()
    {
        if (!auth()->user()->hasRole('owner')&&!auth()->user()->hasRole('staff')) {
            return null;
        }

        //If the owner hasn't set auth()->user()->restaurant_id set it now
        if (auth()->user()->hasRole('owner')){
            if(auth()->user()->restaurant_id==null){
                auth()->user()->restaurant_id=Restorant::where('user_id', auth()->user()->id)->first()->id;
                auth()->user()->update();
            }
            //Get restaurant for currerntly logged in user
            return Restorant::where('user_id', auth()->user()->id)->first();
        }else{
            //Staff
            return Restorant::findOrFail(auth()->user()->restaurant_id);
        }
        

        
    }

    public function ownerOnly()
    {
        if (! auth()->user()->hasRole('owner')) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function ownerAndStaffOnly()
    {
        if (! auth()->user()->hasRole(['owner','staff'])) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function adminOnly()
    {
        if (! auth()->user()->hasRole('admin')) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function simple_replace_spec_char($subject) {
        $char_map = array(
        );
        return $subject;
        //return strtr($subject, $char_map);
    }

    public function replace_spec_char($subject) {
        $char_map = array(
            "??" => "-", "??" => "-", "??" => "-", "??" => "-",
            "??" => "A", "??" => "A", "??" => "A", "??" => "A", "??" => "A", "??" => "A", "??" => "A", "??" => "A", "??" => "A", "??" => "A", "??" => "A", "??" => "A", "??" => "A",
            "??" => "B", "??" => "B", "??" => "B",
            "??" => "C", "??" => "C", "??" => "C", "??" => "C", "??" => "C", "??" => "C", "??" => "C", "??" => "C", "??" => "C",
            "??" => "D", "??" => "D", "??" => "D", "??" => "D", "??" => "D",
            "??" => "E", "??" => "E", "??" => "E", "??" => "E", "??" => "E", "??" => "E", "??" => "E", "??" => "E", "??" => "E", "??" => "E", "??" => "E", "??" => "E", "??" => "E",
            "??" => "F", "??" => "F",
            "??" => "G", "??" => "G", "??" => "G", "??" => "G", "??" => "G", "??" => "G", "??" => "G",
            "??" => "H", "??" => "H", "??" => "H", "??" => "H", "??" => "H",
            "I" => "I", "??" => "I", "??" => "I", "??" => "I", "??" => "I", "??" => "I", "??" => "I", "I" => "I", "??" => "I", "??" => "I", "??" => "I", "??" => "I", "??" => "I", "??" => "I", "??" => "I",
            "??" => "J", "??" => "J",
            "??" => "K", "??" => "K", "??" => "K", "??" => "K", "??" => "K",
            "??" => "L", "??" => "L", "??" => "L", "??" => "L", "??" => "L", "??" => "L", "??" => "L",
            "??" => "M", "??" => "M", "??" => "M",
            "??" => "N", "??" => "N", "??" => "N", "??" => "N", "??" => "N", "??" => "N", "??" => "N", "??" => "N", "??" => "N",
            "??" => "O", "??" => "O", "??" => "O", "??" => "O", "??" => "O", "??" => "O", "??" => "O", "??" => "O", "??" => "O", "??" => "O", "??" => "O", "??" => "O",
            "??" => "P", "??" => "P", "??" => "P",
            "??" => "Q",
            "??" => "R", "??" => "R", "??" => "R", "??" => "R", "??" => "R", "??" => "R",
            "??" => "S", "??" => "S", "??" => "S", "??" => "S", "??" => "S", "??" => "S", "??" => "S",
            "??" => "T", "??" => "T", "??" => "T", "??" => "T", "??" => "T", "??" => "T", "??" => "T",
            "??" => "U", "??" => "U", "??" => "U", "??" => "U", "??" => "U", "??" => "U", "??" => "U", "??" => "U", "??" => "U", "??" => "U", "??" => "U", "??" => "U", "??" => "U", "??" => "U", "??" => "U", "??" => "U",
            "??" => "V", "??" => "V",
            "??" => "Y", "??" => "Y", "??" => "Y", "??" => "Y",
            "??" => "Z", "??" => "Z", "??" => "Z", "??" => "Z", "??" => "Z",
            "??" => "a", "??" => "a", "??" => "a", "??" => "a", "??" => "a", "??" => "a", "??" => "a", "??" => "a", "??" => "a", "??" => "a", "??" => "a", "??" => "a", "??" => "a",
            "??" => "b", "??" => "b", "??" => "b",
            "??" => "c", "??" => "c", "??" => "c", "??" => "c", "??" => "c", "??" => "c", "??" => "c", "??" => "c", "??" => "c",
            "??" => "ch", "??" => "ch",
            "??" => "d", "??" => "d", "??" => "d", "??" => "d", "??" => "d",
            "??" => "e", "??" => "e", "??" => "e", "??" => "e", "??" => "e", "??" => "e", "??" => "e", "??" => "e", "??" => "e", "??" => "e", "??" => "e", "??" => "e", "??" => "e",
            "??" => "f", "??" => "f",
            "??" => "g", "??" => "g", "??" => "g", "??" => "g", "??" => "g", "??" => "g", "??" => "g",
            "??" => "h", "??" => "h", "??" => "h", "??" => "h", "??" => "h",
            "i" => "i", "??" => "i", "??" => "i", "??" => "i", "??" => "i", "??" => "i", "??" => "i", "??" => "i", "??" => "i", "??" => "i", "??" => "i", "??" => "i", "??" => "i", "??" => "i", "??" => "i",
            "??" => "j", "??" => "j", "??" => "j", "??" => "j",
            "??" => "k", "??" => "k", "??" => "k", "??" => "k", "??" => "k",
            "??" => "l", "??" => "l", "??" => "l", "??" => "l", "??" => "l", "??" => "l", "??" => "l",
            "??" => "m", "??" => "m", "??" => "m",
            "??" => "n", "??" => "n", "??" => "n", "??" => "n", "??" => "n", "??" => "n", "??" => "n", "??" => "n", "??" => "n",
            "??" => "o", "??" => "o", "??" => "o", "??" => "o", "??" => "o", "??" => "o", "??" => "o", "??" => "o", "??" => "o", "??" => "o", "??" => "o", "??" => "o",
            "??" => "p", "??" => "p", "??" => "p",
            "??" => "q",
            "??" => "r", "??" => "r", "??" => "r", "??" => "r", "??" => "r", "??" => "r",
            "??" => "s", "??" => "s", "??" => "s", "??" => "s", "??" => "s", "??" => "s", "??" => "s",
            "??" => "t", "??" => "t", "??" => "t", "??" => "t", "??" => "t", "??" => "t", "??" => "t",
            "??" => "u", "??" => "u", "??" => "u", "??" => "u", "??" => "u", "??" => "u", "??" => "u", "??" => "u", "??" => "u", "??" => "u", "??" => "u", "??" => "u", "??" => "u", "??" => "u", "??" => "u", "??" => "u",
            "??" => "v", "??" => "v",
            "??" => "y", "??" => "y", "??" => "y", "??" => "y",
            "??" => "z", "??" => "z", "??" => "z", "??" => "z", "??" => "z", "??" => "z",
            "???" => "tm",
            "@" => "at",
            "??" => "ae", "??" => "ae", "??" => "ae", "??" => "ae", "??" => "ae",
            "??" => "ij", "??" => "ij",
            "??" => "ja", "??" => "ja",
            "??" => "je", "??" => "je",
            "??" => "jo", "??" => "jo",
            "??" => "ju", "??" => "ju",
            "??" => "oe", "??" => "oe", "??" => "oe", "??" => "oe",
            "??" => "sch", "??" => "sch",
            "??" => "sh", "??" => "sh",
            "??" => "ss",
            "??" => "ue",
            "??" => "zh", "??" => "zh",
        );
        return strtr($subject, $char_map);
    }

    public function makeAlias($name)
    {
        $name=$this->replace_spec_char($name);
        $name = str_replace(" ", "-", $name);
        //return strtolower(preg_replace('/[^A-Za-z0-9-]/', '', $name));
        return Str::slug($name, '');
    }

    public function scopeIsWithinMaxDistance($query, $latitude, $longitude, $radius = 25, $table = 'companies')
    {
        $haversine = "(6371 * acos(cos(radians($latitude))
                        * cos(radians(".$table.'.lat))
                        * cos(radians('.$table.".lng)
                        - radians($longitude))
                        + sin(radians($latitude))
                        * sin(radians(".$table.'.lat))))';

        return $query
           ->select(['name', 'id']) //pick the columns you want here.
           ->selectRaw("{$haversine} AS distance")
           ->whereRaw("{$haversine} < ?", [$radius])
           ->orderBy('distance');
    }

    public function getTimieSlots($vendor)
    {


        $tz=$vendor->getConfig('time_zone',config('app.timezone'));

        //Set config based on restaurant
        config(['app.timezone' => $tz]);

        $businessHours=$vendor->getBusinessHours();
        $now = new \DateTime('now', new \DateTimeZone($tz));
        if($businessHours->isClosed()){
            return [];
        }


         //Interval
         $intervalInMinutes = $vendor->getConfig('delivery_interval_in_minutes',config('settings.delivery_interval_in_minutes'));

        $from = Carbon::now()->setTimezone($tz)->diffInMinutes(Carbon::today()->setTimezone($tz)->startOfDay());

        $to = $this->getMinutes($businessHours->nextClose($now)->format('G:i'));



        if($from>$to){
            $to+=1440;
           
        }
       
        //To have clear interval
        $missingInterval = $intervalInMinutes - ($from % $intervalInMinutes); //21

        //Time to prepare the order in minutes
        $timeToPrepare = $vendor->getConfig('time_to_prepare_order_in_minutes',config('settings.time_to_prepare_order_in_minutes')); //30


        //First interval
        $from += $timeToPrepare <= $missingInterval ? $missingInterval : ($intervalInMinutes - (($from + $timeToPrepare) % $intervalInMinutes)) + $timeToPrepare;

        //Enlarge to, since that is not the delivery time
        $to+= $missingInterval+$intervalInMinutes+$timeToPrepare;

        $timeElements = [];
        for ($i = $from; $i <= $to; $i += $intervalInMinutes) {
            array_push($timeElements, $i%1440);
        }
        
        $slots = [];
        for ($i = 0; $i < count($timeElements) - 1; $i++) {
            array_push($slots, [$timeElements[$i], $timeElements[$i + 1]]);
        }

        //INTERVALS TO TIME
        $formatedSlots = [];
        for ($i = 0; $i < count($slots); $i++) {
            $key = $slots[$i][0].'_'.$slots[$i][1];
            $value = $this->minutesToHours($slots[$i][0]).' - '.$this->minutesToHours($slots[$i][1]);
            $formatedSlots[$key] = $value;
        }
        return $formatedSlots;
    }

    
    public function getMinutes($time)
    {
        $parts = explode(':', $time);

        return ((int) $parts[0]) * 60 + (int) $parts[1];
    }

    public function minutesToHours($numMun)
    {
        $h = (int) ($numMun / 60);
        $min = $numMun % 60;
        if ($min < 10) {
            $min = '0'.$min;
        }

        $time = $h.':'.$min;
        if (config('settings.time_format') == 'AM/PM') {
            $time = date('g:i A', strtotime($time));
        }

        return $time;
    }
}
