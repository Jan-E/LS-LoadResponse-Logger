<?php
	class LoadResponseLogger extends \ls\pluginmanager\PluginBase
	{

		static protected $description = 'This plugins logs $oResponses in application/controllers/survey/index.php function action()';
		static protected $name = 'LoadResponseLogger';

		protected $storage = 'DbStorage';

		public function init()
		{
			$sql = "CREATE TABLE IF NOT EXISTS lime_response_log (
				`id` int(11) NOT NULL AUTO_INCREMENT,
				`date` date NOT NULL,
				`surveyid` int(11) NOT NULL,
				`token` varchar(35) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
				`response_count` int(11) NOT NULL,
				`responseid` int(11) NOT NULL,
				`response` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
				PRIMARY KEY (id)
			) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
			Yii::app()->db->createCommand($sql)->execute();
			$this->subscribe('beforeLoadResponse');
		}

		public function beforeLoadResponse()
		{
			$surveyid = $this->event->get('surveyId');
			// load $oResponses
			$responses = $this->event->get('responses');
			$response_count = count($responses);
			foreach ($responses as $response) {
				$date = date("Y-m-d H:i:s", time());
				$token = $response->token;
				$responseid = $response->id;
				$single_response = $response;
				$responsedump = print_r($response,true);
				//echo "<pre>\n\n\n\ndate={$date}\nsurveyid = {$surveyid}\ntoken = {$token}\nresponse count = {$response_count}\nresponseid = {$responseid}\nresponse = {$responsedump}</pre>\n";
				$this->saveLoadResponse($date, $surveyid, $token, $response_count, $responseid, $responsedump);
			}
			if ($response_count == 1) {
				// make sure a single response is returned to $event->get('response') in application/controllers/survey/index.php
				$this->event->set('response', $single_response);
				//echo "<pre>single_response = ".print_r($single_response,true)."</pre>\n";
			}
			// echo "<br /><br /><br /><pre>_SESSION = ".print_r($_SESSION,true)."</pre>\n";
		}

		private function saveLoadResponse($date, $surveyid, $token, $response_count, $responseid, $response) {
			//CREATE ENTRY INTO "lime_response_log"
			$sql = "insert into lime_response_log
				(date, surveyid, token, response_count, responseid, response)
			values
				(:date, :surveyid, :token, :response_count, :responseid, :response)
			";
			$parameters = array(
				date => $date,
				surveyid => $surveyid,
				token => $token,
				response_count => $response_count,
				responseid => $responseid,
				response => $response
			);
			//echo "<br /><br /><br /><pre>parameters = ".print_r($parameters,true)."</pre>\n"; die();
			Yii::app()->db->createCommand($sql)->execute($parameters);
		}
	}
?>
