<?php
	class LoadResponseLogger extends \ls\pluginmanager\PluginBase
	{

		static protected $description = 'Log $_POST and $oResponses, used in application/controllers/survey/index.php function action()';
		static protected $name = 'LoadResponseLogger';

		protected $storage = 'DbStorage';

		public function init()
		{
			$sDBPrefix = Yii::app()->db->tablePrefix;
			$sql = "CREATE TABLE IF NOT EXISTS {$sDBPrefix}response_log (
				`id` int(11) NOT NULL AUTO_INCREMENT,
				`date` datetime NOT NULL,
				`remote_addr` varchar(39) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
				`surveyid` int(11) NOT NULL,
				`token` varchar(35) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
				`response_count` int(11) DEFAULT NULL,
				`responseid` int(11) DEFAULT NULL,
				`response` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
				PRIMARY KEY (id)
			) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
			Yii::app()->db->createCommand($sql)->execute();
			
			$this->subscribe('beforeSurveyPage');
			$this->subscribe('beforeLoadResponse');
			
			// Provides survey specific settings.
			$this->subscribe('beforeSurveySettings');

			// Saves survey specific settings.
			$this->subscribe('newSurveySettings');
		}
		
		protected $settings = array(
			'enabled' => array(
				'type' => 'select',
				'options' => array(
					0 => 'No',
					1 => 'Yes'
				),
				'default' => 0,
				'label' => 'Use load response logger for every survey by default?',
				'help' => 'Overwritable in each Survey setting',
			),
			forceloadsingle => array(
				'type' => 'select',
				'options' => array(
					0 => 'No',
					1 => 'Yes'
				),
				'default' => 0,
				'label' => 'Force load of single response by default?',
				'help' => 'Overwritable in each Survey setting',
			),
		);

		public function beforeSurveySettings()
		{
			$event = $this->event;
			$settings = array(
				'name' => get_class($this),
				'settings' => array(
					'enabled' => array(
						'type' => 'boolean',
						'label' => 'Use for this survey',
						'current' => $this->get('enabled', 'Survey', $event->get('survey'), 0)
					),
					forceloadsingle => array(
						'type' => 'boolean',
						'label' => 'Force load of single response (override usesleft)',
						'current' => $this->get('forceloadsingle', 'Survey', $event->get('survey'), 0)
					)
				)
			);
			$event->set("surveysettings.{$this->id}", $settings);
		}

		/**
		 * Save the settings
		 */
		public function newSurveySettings()
		{
			$event = $this->event;
			foreach ($event->get('settings') as $name => $value)
			{
				/* In order use survey setting, if not set, use global, if not set use default */
				$default=$event->get($name,null,null,isset($this->settings[$name]['default'])?$this->settings[$name]['default']:NULL);
				$this->set($name, $value, 'Survey', $event->get('survey'),$default);
			}
		}

		public function beforeSurveyPage()
		{
			$surveyid = $this->event->get('surveyId');
			if ($this->get('enabled', 'Survey', $surveyid) == false) {
				return;
			}
			$date = date("Y-m-d H:i:s", time());
			$remote_addr = $_SERVER["REMOTE_ADDR"];
			// borrow code at https://github.com/LimeSurvey/LimeSurvey/blob/master/application/models/Token.php#L224
			$token = isset($_REQUEST["token"]) ? preg_replace('/[^0-9a-zA-Z_~]/', '', $_REQUEST["token"]) : NULL;
			$response_count = NULL;
			$responseid = NULL;
			$responsedump = isset($_POST["token"]) ? "beforeSurveyPage post-log: ".print_r($_POST,true) : "beforeSurveyPage no \$_POST[\"token\"], only \$_GET";
			if (isset($_REQUEST["token"])) {
				$this->saveLoadResponse($date, $remote_addr, $surveyid, $token, $response_count, $responseid, $responsedump);
			}
		}
		
		public function beforeLoadResponse()
		{
			$surveyid = $this->event->get('surveyId');
			if ($this->get('enabled', 'Survey', $surveyid) == false) {
				return;
			}
			// load $oResponses
			$responses = $this->event->get('responses');
			$response_count = count($responses);
			foreach ($responses as $response) {
				$date = date("Y-m-d H:i:s", time());
				$remote_addr = $_SERVER["REMOTE_ADDR"];
				$token = $response->token;
				$responseid = $response->id;
				$single_response = $response;
				$responsedump = "beforeLoadResponse: ".print_r($response,true);
				//echo "<pre>\n\n\n\ndate={$date}\nremote_addr = {$remote_addr}\nsurveyid = {$surveyid}\ntoken = {$token}\nresponse count = {$response_count}\nresponseid = {$responseid}\nresponse = {$responsedump}</pre>\n";
				$this->saveLoadResponse($date, $remote_addr, $surveyid, $token, $response_count, $responseid, $responsedump);
			}
			if ($this->get('forceloadsingle', 'Survey', $surveyid) == true) {
				if ($response_count == 1) {
					// make sure a single response is returned to $event->get('response') in application/controllers/survey/index.php
					$this->event->set('response', $single_response);
					//echo "<pre>single_response = ".print_r($single_response,true)."</pre>\n";
				}
			}
			// echo "<br /><br /><br /><pre>_SESSION = ".print_r($_SESSION,true)."</pre>\n";
		}

		private function saveLoadResponse($date, $remote_addr, $surveyid, $token, $response_count, $responseid, $response) {
			//CREATE ENTRY INTO "lime_response_log"
			$sql = "insert into lime_response_log
				(date, remote_addr, surveyid, token, response_count, responseid, response)
			values
				(:date, :remote_addr, :surveyid, :token, :response_count, :responseid, :response)
			";
			$parameters = array(
				date => $date,
				remote_addr => $remote_addr,
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
