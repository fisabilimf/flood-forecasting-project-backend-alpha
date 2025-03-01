<?php

namespace App\Console\Commands;

use App\Models\NotifikasiKontak;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Telegram\Bot\Laravel\Facades\Telegram;

class PredictEveryOneHour extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'predict:everyonehour';
    protected $description = 'Predict the next 1 hour of height of water surface to Flask';

    public function __construct()
    {
        parent::__construct();
    }

    function getSensorData($previousTimestamp)
    {
        $previousTimestamp = Carbon::parse($previousTimestamp);
        $flask_url = config('app.flask_url');
        $sih_3_token_url = config('app.sih_3_token_url');
        $sih_3_get_pos_url = config('app.sih_3_get_pos_url');
        $sih_3_pos_detail_url = config('app.sih_3_pos_detail_url');
        $sih_3_username = config('app.sih_3_username');
        $sih_3_password = config('app.sih_3_password');
        $sih_3_grant_type = config('app.sih_3_grant_type');
        $sih_3_client_id = config('app.sih_3_client_id');
        $sih_3_client_secret = config('app.sih_3_client_secret');

        $dateString =$previousTimestamp->format('Y-m-d');

        $timestamp = Carbon::parse($previousTimestamp);
        $formattedTimestamp = $timestamp->format('Y-m-d H:i:s');

        $client = new Client();
        $url = $sih_3_token_url;
        $data = [
            'username' => $sih_3_username,
            'password' => $sih_3_password,
            'grant_type' => $sih_3_grant_type,
            'client_id' => $sih_3_client_id,
            'client_secret' => $sih_3_client_secret,
        ];

        $response = $client->post($url, [
            'form_params' => $data,
        ]);

        $body = $response->getBody()->getContents();
        $result = json_decode($body, true);
        $token = $result['access_token'];

        $client1 = new Client([
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
        ]);

        $response = $client1->get($sih_3_get_pos_url);
        if ($response->getStatusCode() == 200) {
            $data = json_decode($response->getBody(), true);
            $targetStationNames = ["Puwodadi - S. Welang", "Dhompo - S. Welang", "ARR Cendono", "ARR Lawang"];
            $filteredData = array_filter($data, function ($station) use ($targetStationNames) {
                return in_array($station['nama_stasiun'], $targetStationNames);
            });

            $id_lawang = null;$id_cendono = null;$id_dhompo = null;$id_purwodadi = null;

            foreach ($filteredData as $station) {
                switch ($station['nama_stasiun']) {
                    case 'ARR Lawang':
                        $id_lawang = $station['id'];
                        break;
                    case 'ARR Cendono':
                        $id_cendono = $station['id'];
                        break;
                    case 'Dhompo - S. Welang':
                        $id_dhompo = $station['id'];
                        break;
                    case 'Puwodadi - S. Welang':
                        $id_purwodadi = $station['id'];
                        break;
                }
            }

            $current_hour = Carbon::parse($formattedTimestamp)->hour;

            $responseLawang = $client1->get($sih_3_pos_detail_url.$id_lawang.'/'.$dateString.'/1/1');
            $responseCendono = $client1->get($sih_3_pos_detail_url.$id_cendono.'/'.$dateString.'/1/1');
            $responseDhompo = $client1->get($sih_3_pos_detail_url.$id_dhompo.'/'.$dateString.'/1/2');
            $responsePurwodadi = $client1->get($sih_3_pos_detail_url.$id_purwodadi.'/'.$dateString.'/1/2');

            if ($responseLawang->getStatusCode() == 200) {
                $data_json_lawang = json_decode($responseLawang->getBody(), true);
                $dataLawang = array_filter($data_json_lawang['data'], function ($item) use ($current_hour) {
                    return $item['jam'] == $current_hour;
                });
            }

            if ($responseCendono->getStatusCode() == 200) {
                $data_json_cendono = json_decode($responseCendono->getBody(), true);
                $dataCendono = array_filter($data_json_cendono['data'], function ($item) use ($current_hour) {
                    return $item['jam'] == $current_hour;
                });
            }

            if ($responseDhompo->getStatusCode() == 200) {
                $data_json_dhompo = json_decode($responseDhompo->getBody(), true);
                $dataDhompo = array_filter($data_json_dhompo['data'], function ($item) use ($current_hour) {
                    return $item['jam'] == $current_hour;
                });
            }

            if ($responsePurwodadi->getStatusCode() == 200) {
                $data_json_purwodadi = json_decode($responsePurwodadi->getBody(), true);
                $dataPurwodadi = array_filter($data_json_purwodadi['data'], function ($item) use ($current_hour) {
                    return $item['jam'] == $current_hour;
                });
            }

            $curahHujanCendono = $dataCendono[$current_hour]['nilai'];
            $curahHujanLawang = $dataLawang[$current_hour]['nilai'];
            $levelMukaAirPurwodadi = $dataPurwodadi[$current_hour]['nilai'];
            $levelMukaAirDhompo = $dataDhompo[$current_hour]['nilai'];

            return [
                'curah_hujan_cendono' => (float)$curahHujanCendono,
                'curah_hujan_lawang' => (float)$curahHujanLawang,
                'level_muka_air_purwodadi' => (float)$levelMukaAirPurwodadi,
                'level_muka_air_dhompo' => (float)$levelMukaAirDhompo,
                'tanggal' => $formattedTimestamp,
            ];
        }


    }


    /**
     * Execute the console command.
     * @throws Throwable
     */
    public function handle(): int
    {
        $client = new Client();
        $url = "http://worldtimeapi.org/api/timezone/Asia/Jakarta";
        $response = $client->request('GET', $url);
        $apiResponse = json_decode($response->getBody(), true);
        $datetimeString = $apiResponse['datetime'];
        $carbonDate = Carbon::parse($datetimeString);
        $carbonDate = $carbonDate->setTimeFromTimeString($carbonDate->format('H:00:00'));
        $formattedDateTime = $carbonDate->format('Y-m-d H:i:s');
        $previousTimestamp = $formattedDateTime;

        $data = $this->getSensorData($previousTimestamp);

        if (DB::table('awlr_arr_per_jam')->where('tanggal', $data['tanggal'])->exists()) {
            DB::table('awlr_arr_per_jam')->where('tanggal', $data['tanggal'])->update($data);
        } else {
            DB::table('awlr_arr_per_jam')->insert($data);
        }

        $result_bahaya = "";

        $rowCount = DB::table('awlr_arr_per_jam')->count();
        if ($rowCount >= 5)
        {
            $flask_url = config('app.flask_url');
            $response = Http::post($flask_url);

            if ($response->status() === 200)
            {
                $responseData = $response->json();


                foreach ($responseData as $modelName => $modelData) {
                    foreach ($modelData['predictions'] as $predictedForTime => $prediction) {

                        $parts = explode('_', $modelName);
                        $nama_pos = $parts[0];

                        $threshold = DB::table('stasiun_air')
                            ->select('batas_air_siaga', 'batas_air_awas')
                            ->where('stasiun_air.nama_pos', '=', $nama_pos)
                            ->first();

                        if ($prediction['value'] < $threshold->batas_air_siaga)
                        {
                            $status = "AMAN";
                            $result_bahaya .= "(Status {$status}) Prediksi ketinggian air {$nama_pos} pada jam {$predictedForTime} : {$prediction['value']}.";
                        }
                        else if ($prediction['value'] < $threshold->batas_air_awas)
                        {
                            $status = "SIAGA";
                            $result_bahaya .= "(Status {$status}) Prediksi ketinggian air {$nama_pos} pada jam {$predictedForTime} : {$prediction['value']}.";
                        }
                        else
                        {
                            $status = "BAHAYA";
                            $result_bahaya .= "(Status {$status}) Prediksi ketinggian air {$nama_pos} pada jam {$predictedForTime} : {$prediction['value']}.";
                        }

                        $existingRecord = DB::table('hasil_prediksi')
                            ->where('predicted_for_time', $predictedForTime)
                            ->first();

                        if ($existingRecord)
                        {
                            DB::table('hasil_prediksi')
                                ->where('predicted_for_time', $predictedForTime)
                                ->update([
                                    'prediksi_level_muka_air_purwodadi_lstm' => $modelName === 'purwodadi_lstm' ? $prediction['value'] : $existingRecord->prediksi_level_muka_air_purwodadi_lstm,
                                    'prediksi_level_muka_air_purwodadi_gru' => $modelName === 'purwodadi_gru' ? $prediction['value'] : $existingRecord->prediksi_level_muka_air_purwodadi_gru,
                                    'prediksi_level_muka_air_purwodadi_tcn' => $modelName === 'purwodadi_tcn' ? $prediction['value'] : $existingRecord->prediksi_level_muka_air_purwodadi_tcn,
                                    'prediksi_level_muka_air_dhompo_lstm' => $modelName === 'dhompo_lstm' ? $prediction['value'] : $existingRecord->prediksi_level_muka_air_dhompo_lstm,
                                    'prediksi_level_muka_air_dhompo_gru' => $modelName === 'dhompo_gru' ? $prediction['value'] : $existingRecord->prediksi_level_muka_air_dhompo_gru,
                                    'prediksi_level_muka_air_dhompo_tcn' => $modelName === 'dhompo_tcn' ? $prediction['value'] : $existingRecord->prediksi_level_muka_air_dhompo_tcn,
                                    'status_muka_air' => $existingRecord->status_muka_air,
                                ]);
                        }
                        else
                        {
                            DB::table('hasil_prediksi')->insert([
                                'predicted_for_time' => $predictedForTime,
                                'predicted_from_time' => $modelData['predicted_from_time'],
                                'status_muka_air' => null,
                                'prediksi_level_muka_air_purwodadi_lstm' => $modelName === 'purwodadi_lstm' ? $prediction['value'] : null,
                                'prediksi_level_muka_air_purwodadi_gru' => $modelName === 'purwodadi_gru' ? $prediction['value'] : null,
                                'prediksi_level_muka_air_purwodadi_tcn' => $modelName === 'purwodadi_tcn' ? $prediction['value'] : null,
                                'prediksi_level_muka_air_dhompo_lstm' => $modelName === 'dhompo_lstm' ? $prediction['value'] : null,
                                'prediksi_level_muka_air_dhompo_gru' => $modelName === 'dhompo_gru' ? $prediction['value'] : null,
                                'prediksi_level_muka_air_dhompo_tcn' => $modelName === 'dhompo_tcn' ? $prediction['value'] : null,
                                'status_muka_air' => $status,
                            ]);
                        }
                    }
                }

                // Retrieve all contacts
                $contacts = NotifikasiKontak::whereNotNull('apiKey')->get();

                // Arrays to store phone numbers and API keys
                $array_no_telp = [];
                $array_api_key = [];

                // Populate the arrays while maintaining the indices
                foreach ($contacts as $contact) {
                    $array_no_telp[] = $contact->no_telp;
                    $array_api_key[] = $contact->apiKey;
                }

                // Guzzle HTTP client
                $client = new Client();
                if (!empty($array_no_telp)) {
                    foreach ($array_no_telp as $index => $phone_number) {
                        $apiKey = $array_api_key[$index];
                        $message = urlencode($result_bahaya); // Encode the entire message

                        $url = "https://api.callmebot.com/whatsapp.php?phone={$phone_number}&text={$message}&apikey={$apiKey}";

                        try {
                            $response = $client->request('GET', $url);
                            if ($response->getStatusCode() == 200) {
                                echo "Message sent to {$phone_number}\n";
                            } else {
                                echo "Failed to send message to {$phone_number}\n";
                            }
                        } catch (\Exception $e) {
                            echo "Error sending message to {$phone_number}: " . $e->getMessage() . "\n";
                        }
                    }
                }

            }
            $this->info('Prediction executed successfully!');
        }
        $this->info('Cron executed successfully!');
        return 0;

    }
}
