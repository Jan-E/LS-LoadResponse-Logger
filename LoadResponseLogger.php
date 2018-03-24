<?php
	class LoadResponseLogger extends \ls\pluginmanager\PluginBase
	{

		static protected $description = 'Log $_POST and $oResponses, used in application/controllers/survey/index.php function action()';
		static protected $name = 'LoadResponseLogger';

		protected $storage = 'DbStorage';

		public function init()
		{
			if ($this->get('logtable', 'Survey', $surveyid) == true) {
				$sDBPrefix = Yii::app()->db->tablePrefix;
				$mysql = "CREATE TABLE IF NOT EXISTS {$sDBPrefix}response_log (
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
				$pgsql = "CREATE TABLE IF NOT EXISTS {$sDBPrefix}response_log (
					id SERIAL,
					date timestamp without time zone NOT NULL,
					remote_addr character varying(39) NOT NULL,
					surveyid integer NOT NULL,
					token character varying(35),
					response_count integer,
					responseid integer,
					response text
				)";
				$constring = Yii::app()->db->connectionString;
				if (stristr($constring, "mysql:") !== false) $sql = $mysql;
				else $sql = $pgsql;
				Yii::app()->db->createCommand($sql)->execute();
			}
			
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
				'label' => 'Use load response logger by default?',
				'help' => 'Overwritable in the Survey settings',
			),
			'logtable' => array(
				'type' => 'select',
				'options' => array(
					0 => 'No',
					1 => 'Yes'
				),
				'default' => 0,
				'label' => 'Store logs in response_log table by default?',
				'help' => 'Overwritable in the Survey settings',
			),
			'logsurvey' => array(
				'type' => 'select',
				'options' => array(
					0 => 'No',
					1 => 'Yes'
				),
				'default' => 0,
				'label' => 'Store logs in Response Log \'survey\' by default?',
				'help' => 'Overwritable in the Survey settings',
			),
			'logsurveyid' => array(
				'type' => 'int',
				'default' => 999999,
				'label' => 'Survey ID of the Response Log \'survey\'',
				'help' => 'Make sure a Survey with this ID is activated.<br />Use limesurvey_survey_999999.lss to add it.',
			),
			'forceloadsingle' => array(
				'type' => 'select',
				'options' => array(
					0 => 'No',
				),
				'default' => 0,
				'label' => 'Do not force load of single response by default',
				'help' => 'Only configurable in the Survey settings',
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
						'label' => 'Use plugin for this survey',
						'current' => $this->get('enabled', 'Survey', $event->get('survey'), $this->get('enabled'))
					),
					'logtable' => array(
						'type' => 'boolean',
						'label' => 'Store logs in response_log table',
						'current' => $this->get('logtable', 'Survey', $event->get('survey'), $this->get('logtable'))
					),
					'logsurvey' => array(
						'type' => 'boolean',
						'label' => 'Store logs in response_log table',
						'current' => $this->get('logsurvey', 'Survey', $event->get('survey'), $this->get('logsurvey'))
					),
					'logsurveyid' => array(
						'type' => 'int',
						'label' => 'Survey ID of the Response Log \'survey\'',
						'help' => 'Make sure a Survey with this ID is activated. Use the lss to add it.',
						'current' => $this->get('logsurveyid', 'Survey', $event->get('survey'), $this->get('logsurveyid'))
					),
					'forceloadsingle' => array(
						'type' => 'boolean',
						'options' => array(
							0 => 'No',
							1 => 'Yes'
						),
						'label' => 'Force load of single response',
						'help' => 'Do not do this, unless you are absolutely sure (overrides usesleft and other LS settings)',
						'current' => $this->get('forceloadsingle', 'Survey', $event->get('survey'), $this->get('forceloadsingle'))
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
			$sDBPrefix = Yii::app()->db->tablePrefix;
			$parameters = array(
				'date' => $date,
				'remote_addr' => $remote_addr,
				'surveyid' => $surveyid,
				'token' => $token,
				'response_count' => $response_count,
				'responseid' => $responseid,
				'response' => $response
			);
			if ($this->get('logtable', 'Survey', $surveyid) == true) {
				//CREATE ENTRY INTO "{$sDBPrefix}response_log"
				$sql = "insert into {$sDBPrefix}response_log
					(date, remote_addr, surveyid, token, response_count, responseid, response)
				values
					(:date, :remote_addr, :surveyid, :token, :response_count, :responseid, :response)
				";
				//echo "<br /><br /><br /><pre>".$sql."\nparameters = ".print_r($parameters,true)."</pre>\n"; die();
				Yii::app()->db->createCommand($sql)->execute($parameters);
			}
			if ($this->get('logsurvey', 'Survey', $surveyid) == true) {
				$sid = $this->get('logsurveyid');
				$sql = "SELECT title, gid, qid FROM {{questions}} WHERE sid={$sid} and parent_qid=0 ORDER BY qid";
				$qidarr = Yii::app()->db->createCommand($sql)->queryAll();
				$fields = array();
/*
$qidarr = Array
(
    [0] => Array
        (
            [title] => date
            [gid] => 465
            [qid] => 4328
        )

    [1] => Array
        (
            [title] => remoteaddr
            [gid] => 465
            [qid] => 4329
        )

    [2] => Array
        (
            [title] => surveyid
            [gid] => 465
            [qid] => 4330
        )

    [3] => Array
        (
            [title] => token
            [gid] => 465
            [qid] => 4331
        )

    [4] => Array
        (
            [title] => responsecount
            [gid] => 465
            [qid] => 4332
        )

    [5] => Array
        (
            [title] => responseid
            [gid] => 465
            [qid] => 4333
        )

    [6] => Array
        (
            [title] => response
            [gid] => 465
            [qid] => 4334
        )

)
*/
				foreach($qidarr as $question) {
					if ($question['title'] == 'remoteaddr') $fields['remote_addr'] = $sid.'X'.$question['gid'].'X'.$question['qid'];
					else if ($question['title'] == 'responsecount') $fields['response_count'] = $sid.'X'.$question['gid'].'X'.$question['qid'];
					else $fields[$question['title']] = $sid.'X'.$question['gid'].'X'.$question['qid'];
				}
/*
$fields = Array
(
    [date] => 999999X465X4328
    [remote_addr] => 999999X465X4329
    [surveyid] => 999999X465X4330
    [token] => 999999X465X4331
    [response_count] => 999999X465X4332
    [responseid] => 999999X465X4333
    [response] => 999999X465X4334
)
*/
				// INSERT record into Response Log seurvey
				$sql = "insert into {$sDBPrefix}survey_{$sid}
					({$fields['date']}, {$fields['remote_addr']}, {$fields['surveyid']}, {$fields['token']}, {$fields['response_count']}, {$fields['responseid']}, {$fields['response']})
				values
					(:date, :remote_addr, :surveyid, :token, :response_count, :responseid, :response)
				";
				// die("logsurveyid = $sid\n<pre>".$sql."\n".print_r($parameters,true)."\n".print_r($qidarr,true)."</pre>\n");
				Yii::app()->db->createCommand($sql)->execute($parameters);
			}
/*
CREATE TABLE IF NOT EXISTS lime_survey_999999 (
  id int(11) NOT NULL AUTO_INCREMENT,
  token varchar(35) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  submitdate datetime DEFAULT NULL,
  lastpage int(11) DEFAULT NULL,
  startlanguage varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  999999X465X4328 datetime DEFAULT NULL,
  999999X465X4329 text COLLATE utf8mb4_unicode_ci,
  999999X465X4330 decimal(30,10) DEFAULT NULL,
  999999X465X4331 text COLLATE utf8mb4_unicode_ci,
  999999X465X4332 decimal(30,10) DEFAULT NULL,
  999999X465X4333 decimal(30,10) DEFAULT NULL,
  999999X465X4334 text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (id),
  KEY idx_survey_token_999999_45577 (token)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
*/
		}
	}
?>
