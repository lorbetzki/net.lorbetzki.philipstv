<?php

declare(strict_types=1);
	class PhilipsTV extends IPSModule
	{
		public function Create()
		{
			//Never delete this line!
			parent::Create();
			$this->RegisterPropertyString("Host", '');
			
			$this->RegisterAttributeString("SecretKey", '');
			$this->RegisterAttributeString("DeviceID", '');
			$this->RegisterAttributeString("AuthKey", '');
			$this->RegisterAttributeInteger("AuthTimestamp", 0);
			$this->RegisterAttributeInteger("TVPin", 0);
			
			$this->SetVisualizationType(1);

		}

		public function Destroy()
		{
			//Never delete this line!
			parent::Destroy();
		}

		public function ApplyChanges()
		{
			//Never delete this line!
			parent::ApplyChanges();


			if ($this->ReadPropertyString("Host") == '')
			{
				$this->SetStatus(201); // no Host
            	return false;
			}
			else
			{
				$this->SetStatus(102); // 
			}

		}

		public function GetConfigurationForm()
		{
			$jsonform = json_decode(file_get_contents(__DIR__."/form.json"), true);
			
			if ((!$this->ReadAttributeString("AuthKey")) AND ($this->ReadPropertyString("Host")) )
			{
				$jsonform["actions"][0]["visible"] = true;
				$jsonform["actions"][1]["visible"] = true;
				$jsonform["actions"][2]["visible"] = true;
				$jsonform["actions"][3]["visible"] = true;
			}
			
			
			return json_encode($jsonform);
		}


		private function createRandomString(int $length)
		{
			return substr(str_shuffle(str_repeat('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', mt_rand(1,$length))), 1,$length);
		}

		private function startPairing() 
		{
			// start pairing process
			$Host = $this->ReadPropertyString('Host');
			
			if (!$this->ReadAttributeString("DeviceID"))
			{
				$this->WriteAttributeString("DeviceID", $this->createRandomString(32));
				$this->SendDebug(__FUNCTION__, "create DeviceID: ". $this->ReadAttributeString("DeviceID"), 0);
			}

			$DeviceID = $this->ReadAttributeString('DeviceID');

			$data=[
					'device' => [
									'app_id' => 'gapp.id',
									'id'  => $DeviceID, 
									'device_name' => 'heliotrope',
									'device_os' => 'Android',
									'app_name' => 'ApplicationName',
									'type' => 'native',
								],
					'scope' =>  [ 
									'read', 
									'write', 
									'control'
								]            
					];

			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, 'https://'.$Host.':1926/6/pair/request');
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
			curl_setopt($ch, CURLOPT_HTTPHEADER, [
				'Content-Type: application/x-www-form-urlencoded',
			]);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
			curl_setopt($ch, CURLOPT_TIMEOUT, 60);
			$response = curl_exec($ch);
			$curl_error = curl_error($ch);

			if (empty($response) || $response === false || !empty($curl_error)) {
				$this->SendDebug(__FUNCTION__, 'Empty answer from TV: ' . $curl_error, 0);
				$this->SetStatus(202);
				return false;
				}
			$this->SendDebug(__FUNCTION__, "Start Pairing result: ". $response, 0);
			curl_close($ch);
						
			$result = json_decode($response, true);

			$this->WriteAttributeString("AuthKey", $result['auth_key']);
			$this->WriteAttributeInteger("AuthTimestamp", $result['timestamp']);
			

			if ($result['error_text'] === "Authorization required")
			{
				$this->SendDebug(__FUNCTION__, 'Answer from TV: ' . $result['error_text'], 0);
				$this->SetStatus(203);
			}
			return;
		}

		private function createAuth()
		{
			// create authkey and registering to tv
			$Host = $this->ReadPropertyString('Host');
			if (!$this->ReadAttributeString("SecretKey"))
			{
				//$this->WriteAttributeString("SecretKey", $this->createRandomString(89));
				$this->WriteAttributeString("SecretKey", 'ZmVay1EQVFOaZhwQ4Kv81ypLAZNczV9sG4KkseXWn1NEk6cXmPKO/MCa9sryslvLCFMnNe4Z4CPXzToowvhHvA==');
				$this->SendDebug(__FUNCTION__, "create SecretKey: ". $this->ReadAttributeString("SecretKey"), 0);
			}

			$auth_timestamp = $this->ReadAttributeInteger('AuthTimestamp');
			$tvpin = $this->ReadAttributeInteger('TVPin');
			$AuthKey = $this->ReadAttributeString("AuthKey");
			$DeviceID = $this->ReadAttributeString('DeviceID');

			//decode Signaturekey
			$secret_key = base64_decode($this->ReadAttributeString('SecretKey'));

			$authdata = $auth_timestamp.$tvpin;
			$signature =  base64_encode(hash_hmac('sha1', $secret_key, $authdata, true));
		
			$this->SendDebug(__FUNCTION__, "create signature: ". $authdata."->".$signature, 0);
		
			$data=[
					'device' => [
									'device_name' => 'heliotrope',
									'device_os' =>  'Android',
									'app_name' => 'ApplicationName',
									'type' => 'native',
									'app_id' => 'app.id',
									'id' => $DeviceID
								],
		
					'auth' =>   [ 
									'auth_AppId' => '1',
									'pin' => $tvpin,
									'auth_timestamp' => $authdata,
									'auth_signature' => $signature
								]
		
			];
			$this->SendDebug(__FUNCTION__, "create Json: ". json_encode($data), 0);

			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, 'https://'.$Host.':1926/6/pair/grant');
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
			curl_setopt($ch, CURLOPT_HTTPHEADER, [
				'Content-Type: application/x-www-form-urlencoded',
			]);
			curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
			curl_setopt($ch, CURLOPT_USERPWD, $DeviceID.':'.$AuthKey);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
			curl_setopt($ch, CURLOPT_TIMEOUT, 60);

			$response = curl_exec($ch);
			$curl_error = curl_error($ch);
	
			if (empty($response) || $response === false || !empty($curl_error)) {
				$this->SendDebug(__FUNCTION__, 'Empty answer from TV: ' . $curl_error, 0);
				$this->SetStatus(202);
				return false;
			}

			$this->SendDebug(__FUNCTION__, "Auth response: ". $response, 0);
			curl_close($ch);
			$result = json_decode($response, true);

			if ($result['error_text'] === "Pairing completed")
			{
				$this->SendDebug(__FUNCTION__, 'Answer from TV: ' . $result['error_text'], 0);
				$this->SetStatus(102);
			}

			return;
		}

		public function sendCmd(string $cmd)
		{
			$Host = $this->ReadPropertyString('Host');
			$DeviceID = $this->ReadAttributeString('DeviceID');
			$AuthKey = $this->ReadAttributeString("AuthKey");

			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, 'https://'.$Host.':1926/6/'.$cmd);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
			curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
			curl_setopt($ch, CURLOPT_USERPWD, $DeviceID.':'.$AuthKey);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
			curl_setopt($ch, CURLOPT_TIMEOUT, 60);

			$response = curl_exec($ch);
			$curl_error = curl_error($ch);
	
			if (empty($response) || $response === false || !empty($curl_error)) {
				$this->SendDebug(__FUNCTION__, 'Empty answer from TV: ' . $curl_error, 0);
				$this->SetStatus(202);

				return false;
				}
			$this->SendDebug(__FUNCTION__, "Send Key: ". $response, 0);
			curl_close($ch);

			return $response;
		}

		public function sendKey(string $key)
		{
			$Host = $this->ReadPropertyString('Host');
			$DeviceID = $this->ReadAttributeString('DeviceID');
			$AuthKey = $this->ReadAttributeString("AuthKey");

			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, 'https://'.$Host.':1926/input/key');
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
			curl_setopt($ch, CURLOPT_HTTPHEADER, [
				'Content-Type: application/x-www-form-urlencoded',
			]);
			curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
			curl_setopt($ch, CURLOPT_USERPWD, $DeviceID.':'.$AuthKey);
			curl_setopt($ch, CURLOPT_POSTFIELDS, '{"key":"'.$key.'"}');
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
			curl_setopt($ch, CURLOPT_TIMEOUT, 60);

			$response = curl_exec($ch);
			$curl_error = curl_error($ch);
	
			if (empty($response) || $response === false || !empty($curl_error)) {
				$this->SendDebug(__FUNCTION__, 'Empty answer from TV: ' . $curl_error, 0);
				$this->SetStatus(202);

				return false;
				}
			$this->SendDebug(__FUNCTION__, "Send Key: ". $response, 0);
			curl_close($ch);
			return $response;

		}

		public function RequestAction($Ident, $Value)
		{
			switch ($Ident) {
				case "startPairing":
					$this->SendDebug(__FUNCTION__, "start getting the auth key: ", 0);
					$this->startPairing();
				break;
				case "createAuth":
					$this->SendDebug(__FUNCTION__, "Start Pairing Process: ", 0);
					$this->WriteAttributeInteger('TVPin',$Value);
					$this->createAuth();
				break;
				case "reset":
					$this->SendDebug(__FUNCTION__, "reset all Variables:", 0);
					$this->WriteAttributeString("SecretKey", '');
					$this->WriteAttributeString("DeviceID", '');
					$this->WriteAttributeString("AuthKey", "");
					$this->WriteAttributeInteger("AuthTimestamp", 0);
					$this->ReloadForm();
					$this->SetStatus(102); 
				break;
			}
		}

		public function GetVisualizationTile()
		{
			//$initialHandling = '<script>handleMessage(' . json_encode($this->GetFullUpdateMessage()) . ')</script>';

			// Füge statisches HTML aus Datei hinzu
			$module = file_get_contents(__DIR__ . '/module.html');
			//			return $module .$initialHandling;
			return $module;
		}
	}