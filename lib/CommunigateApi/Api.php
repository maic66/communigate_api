<?php

namespace CommunigateApi;

use CommunigateApi\ApiException;

/**
 * CommunigateApi API
 *
 * API for CommunigateApi MailServer.
 *
 * @author Shaun Mitchell
 * @author Tyler Rooney
 */
class Api {

	/** Commands that this object can run */
	const API_ESCAPE = '\e';
	const API_COMMAND_USER = 'USER ';
	const API_COMMAND_PASS = 'PASS ';
	const API_COMMAND_INLINE = 'INLINE';
	const API_COMMAND_QUIT = 'QUIT';
	const API_LIST_DOMAINS = 'ListDomains';
	const API_LIST_ACCOUNTS = 'ListAccounts "$$"';
	const API_GET_ACCOUNT_RULES = 'GetAccountRules "$$"';
	const API_GET_ACCOUNT_SETTINGS = 'GetAccountSettings "$$"';
	const API_UPDATE_ACCOUNT_SETTINGS = 'UpdateAccountSettings "$account$" $setting$';
	const API_SET_ACCOUNT_RULES = 'SetAccountMailRules "$account$" $rule$';
	const API_CREATE_ACCOUNT = 'CreateAccount "$name$$domain$" {Password = "$password$";}';
	const API_DELETE_ACCOUNT = 'DeleteAccount "$$"';
	const API_RESET_PASSWORD = 'SetAccountPassword "$account$" PASSWORD "$password$"';
	const API_VERIFY_PASSWORD = 'VerifyAccountPassword "$account$" PASSWORD "$password$"';
	const API_RENAME_ACCOUNT = 'RenameAccount "$old_account$" into "$new_account$"';
	const API_LIST_FORWARDER = 'ListForwarders $$';
	const API_GET_FORWARDER = 'GetForwarder $forwarder$$domain$';
	const API_GET_ACCOUNT_INFO = 'GetAccountInfo $account$$domain$';
	const API_GET_ACCOUNT_EFF_SETTINGS = 'GetAccountEffectiveSettings $account$$domain$';
	const API_GET_CONTROLLER = 'GetCurrentController';
	const API_LIST_LISTS = 'ListLists "$$"';
	const API_CREATE_LIST = 'CreateList $list$ for $account$';
	const API_UPDATE_LIST = 'UpdateList $list$ $settings$';
	const API_DELETE_LIST = 'DeleteList $list$';
	const API_GET_LIST = 'GetList $list$';
	const API_ADD_LIST_SUBSCRIBER = 'List $list$ $operation$ $silently$ $confirm$ $email$';
	const API_SET_POSTING_MODE = 'SetPostingMode $list$ FOR $email$ $mode$';
	const API_LIST_SUBSCRIBERS = 'ListSubscribers $list$';
	const API_GET_SUBSCRIBER_INFO = 'GetSubscriberInfo $list$ NAME $email$';

	/** Rules structures */
	const API_VACATION_STRUCT = '( 2, "#Vacation", (("Human Generated", "---"), (From, "not in", "#RepliedAddresses")), ( ("Reply with", "$$"), ("Remember \'From\' in", RepliedAddresses) ) )';
	const API_EMAIL_REDIRECT_STRUCT = '( 1, "#Redirect", (), (("Mirror to", "$$"), (Discard, "---")) )';

	const TYPE_SEND = 'SEND';
	const TYPE_RECEIVE = 'RECEIVE';

	/**
	 * @var array Connection configuration
	 */
	private $config;

	/**
	 * @var resource
	 */
	public $socket;

	/**
	 * @var boolean Is connected?
	 */
	public $connected;

	/**
	 * Cached request responses
	 *
	 * @var array
	 */
	private $cache = array();

	/**
	 * @var string The results from command
	 */
	public $output;

	/**
	 * Success?
	 *
	 * @var boolean
	 */
	private $success;

	/**
	 * Toggles console output
	 *
	 * @var bool
	 */
	private $verbose;

	/**
	 * @var \Monolog\Logger
	 */
	private $logger;


	/**
	 * Non critical errors
	 *
	 * These errors are non critical errors from the CLI
	 *
	 * 200 = OK
	 * 300 = Expecting more input
	 * 520 = Account name already exists
	 * 500 = Unknown command
	 * 512 = Unknown secondary domain name
	 * 513 = Unkown user account
	 *
	 * @var Array
	 */
	private $CGC_KNOWN_SUCCESS_CODES = Array(
		200 => 'OK',
		201 => 'OK (inline)',
		300 => 'Expecting more input',
	);

	/**
	 * Connect to API server
	 *
	 * This method will attempt to make a connection to the CommuniGate API server.
	 */
	public function connect($options = array()) {

		$defaults = array(
			'host' => '127.0.0.1',
			'login' => null,
			'password' => null,
			'port' => 106,
			'timeout' => 10,
		);

		if ($options) {
			$this->config = $options + $defaults;
		}

		$this->config = $this->config + $defaults;

		$host = $this->config['host'];
		$port = $this->config['port'];
		$login = $this->config['login'];
		$password = $this->config['password'];
		$timeout = $this->config['timeout'];
		$errorCode = '';
		$errorMessage = '';

		$this->socket = @fsockopen($host, $port, $errorCode, $errorMessage, $timeout);

		if (!$this->socket) {
			throw new ApiException("CommunigateAPI: Failed to connect to at {$host}:{$port} ({$errorCode}: {$errorMessage})");
		}

		$this->log('Connected to ' . $host, self::TYPE_SEND);


		$this->connected = true;
		fgets($this->socket); // chomp welcome string
		$this->clearCache();

		$this->sendAndParse(self::API_COMMAND_USER . $login);

		$this->sendAndParse(self::API_COMMAND_PASS . $password);

		/** Set the CLI response to "INLINE", faster repsonse time */
		$this->sendAndParse(self::API_COMMAND_INLINE);

		return true;

	}

	public function disconnect() {
		if ($this->socket) {
            fputs($this->socket, self::API_COMMAND_QUIT . chr(10));
			fclose($this->socket);
		}
        $this->clearCache();
		$this->socket = NULL;
		$this->connected = false;
	}

	/**
	 * Class CommuniGate API
	 *
	 * This method is the constructor. It is called when the object is created. It doens't do much
	 * but set the debugging properties of the object.
	 */
	public function __construct(array $options) {

		if (array_key_exists('logger', $options)) {
			$this->logger = $options['logger'];
			unset($options['logger']);
		}

		if (array_key_exists('verbose', $options)) {
			$this->verbose = (bool)$options['verbose'];
			unset($options['verbose']);
		}

		$this->config = $options;
	}

	/**
	 * Disconnect on destruct
	 */
	public function __destruct() {
		$this->disconnect();
	}

	/**
	 * Get domains
	 *
	 * Returns a list of domains
	 */
	public function get_domains() {

		$this->sendAndParse(self::API_LIST_DOMAINS);

		return $this->success ? $this->output : array();
	}

	/**
	 *
	 * Get forwarders
	 *
	 * This method will return a list of all the forwarders for a domain. It
	 * will then get the email address that is being forwarded to.
	 *
	 * @param $domain
	 * @return array
	 */
	public function get_forwarders($domain) {

		$forwarders = Array();

		$this->sendAndParse(str_replace('$$', $domain, self::API_LIST_FORWARDER));

		if ($this->output != NULL) {

			foreach ($this->output as $item) {

				$this->parse_response(str_replace('$domain$', '@' . $domain, str_replace('$forwarder$', $item, self::API_GET_FORWARDER)));

				$forwarders[$item] = $this->output[0];
			}
		}

		return $forwarders;

	}

	/**
	 * Get accounts
	 *
	 * This method will return the account list for a domain.
	 *
	 * @var String $domain The domain name to get the account listing for
	 * @return mixed
	 */
	public function get_accounts($domain) {

		$response = $this->send(str_replace('$$', $domain, self::API_LIST_ACCOUNTS));
		$this->parse_response($response);

		$accounts = substr($response, 5, -3); // Chop off the 200 { whatever }
		$accounts = preg_replace('/=.*?;/',';', $accounts);
		$accounts = explode(';', $accounts);

		array_pop($accounts);

		return $accounts;
	}

	/**
	 * Get account details
	 *
	 * This method will return the details of a single account. The account
	 * details is something like; ExternalINBOX = No, Password = 1234, Rules ={....}
	 *
	 * @param $domain
	 * @param $account
	 * @return String
	 */
	public function get_account_details($domain, $account) {
		$this->sendAndParse(str_replace('$account$', $account, str_replace('$domain$', '@' . $domain, self::API_GET_ACCOUNT_EFF_SETTINGS)));

		return $this->success ? $this->output : null;
	}

	/**
	 * Get account rules
	 *
	 * This method will return the rules of a single account.
	 *
	 * @param $domain
	 * @param $account
	 * @return Array
	 */
	private function get_account_rules($domain, $account) {
		$this->sendAndParse(str_replace('$account$', $account, str_replace('$domain$', '@' . $domain, self::API_GET_ACCOUNT_EFF_SETTINGS)));

		if ($this->success) {
			return $this->parse_processed_output_to_rules_array($this->output);
		}

		return array();
	}

	public function get_account_password($domain, $account) {

		$password = null;

		$output = $this->get_account_details($domain, $account);

		foreach ($output as $value) {
			if (preg_match('/^Password="?([^"]*)"?/', $value, $matches)) {
				$password = isset($matches[1]) ? $matches[1] : null;
			}
		}
		return $password;
	}
	/**
	 * Get account storage
	 *
	 * This method will get an accounts max storage allowed and used.
	 *
	 * @param $domain
	 * @param $account
	 * @return array
	 */
	public function get_account_storage($domain, $account) {

		$this->sendAndParse(str_replace('$account$', $account, str_replace('$domain$', '@' . $domain, self::API_GET_ACCOUNT_INFO)));

		/** Store the output in local variable */
		$output = $this->output;

		$this->sendAndParse(str_replace('$account$', $account, str_replace('$domain$', '@' . $domain, self::API_GET_ACCOUNT_EFF_SETTINGS)));

		/** Combine the two outputs */
		$this->output = array_merge($output, $this->output);

		$storage_used = 0;
		$max = 0;

		/** Loop through the output to get the storage and maxstorage values */
		foreach ($this->output as $value) {
			if (preg_match('/^storageused/i', $value)) {
				$storage_used = substr($value, strpos($value, '=') + 1);
			}

			if (preg_match('/^maxaccountsize/i', $value)) {
				$max = substr($value, strpos($value, '=') + 1);
			}
		}

		/** convert all unknown values from bytes to megabytes */
		foreach (array('max', 'storage_used') as $valToClean) {
			if (!preg_match('/(M|K)$/i', $$valToClean)) {
				$$valToClean = round(($$valToClean / 1024) / 1024, 2);
			}

		}

		$this->success = TRUE;

		return Array('max' => (int) $max, 'used' => (int) $storage_used);
	}

	/**
	 * Delete account
	 *
	 * This method will delete the account
	 *
	 * @param $domain
	 * @param $account
	 * @return bool
	 */
	public function delete_account($domain, $account) {

		$this->sendAndParse(str_replace('$$',  $account . '@' . $domain, self::API_DELETE_ACCOUNT));

		return true;
	}

	/**
	 * Create account
	 *
	 * This method will create an account
	 *
	 * @param $domain
	 * @param $account
	 * @param $password
	 * @return bool
	 * @throws ApiException
	 */
	public function create_account($domain, $account, $password) {

		/** Make sure that account name is in correct format */
		if (!preg_match('/^[a-zA-Z0-9,._%+-]+$/i', $account)) {
			throw new ApiException('Invalid account name');
		}

		$password = $this->escape($password);

		/** Create the command */
		$command = str_replace('$domain$', $domain, self::API_CREATE_ACCOUNT);
		$command = str_replace('$name$', $account . '@', $command);
		$command = str_replace('$password$', $password, $command);

		$this->sendAndParse($command);

		return true;
	}

	/**
	 * Reset password
	 *
	 * This method will reset an account's password
	 *
	 * @param $domain
	 * @param $account
	 * @param $password
	 * @return bool
	 */
	public function reset_password($domain, $account, $password) {

		$password = $this->escape($password);

		/** Create the command */
		$command = str_replace('$account$', $account . '@' . $domain, self::API_RESET_PASSWORD);
		$command = str_replace('$password$', $password, $command);

		$this->sendAndParse($command);
		$this->clearCache();

		return true;
	}

	/**
	 * Verify password
	 *
	 * This method will verify an account's password
	 *
	 * @param $domain
	 * @param $account
	 * @param $password
	 * @return bool
	 */
	public function verify_password($domain, $account, $password) {

		/** Create the command */
		$command = str_replace('$account$', $account . '@' . $domain, self::API_VERIFY_PASSWORD);
		$command = str_replace('$password$', $password, $command);

		try {
			$this->sendAndParse($command);
		} catch (ApiException $e) {

			// Check for invalid password code, otherwise pass along exception
			if (515 == $e->getCode()) {
				return false;
			} else {
				throw $e;
			}
		}


		return $this->success;
	}

	/**
	 * Rename Account
	 *
	 * This method will rename an account.
	 *
	 * @param $domain
	 * @param $account
	 * @param $new_name
	 * @return bool
	 */
	public function rename_account($domain, $account, $new_name) {

		/** Create the command */
		$command = str_replace('$old_account$', $account . '@' . $domain, self::API_RENAME_ACCOUNT);
		$command = str_replace('$new_account$', $new_name . '@' . $domain, $command);

		$this->sendAndParse($command);

		return $this->success;
	}

	/**
	 * Get rule
	 *
	 * This method will return a rule. The rule needs to be passed as it looks in the CommuniGate settings file.
	 * Example: A vacation notice is identified like #vacation but the email forwarding is #redirect
	 *
	 * @param string $account The account to get the rule from
	 * @param string $domain The domain the account is registered to
	 * @param string $ruleName The rule to get
	 * @return array|bool
	 */
	private function get_account_rule($domain, $account, $ruleName) {

		$rules = $this->get_account_rules($domain, $account);

		foreach ($rules as $rule) {
			$ruleName = '#' . str_replace('#', '', $ruleName);
			if (preg_match('/'.$ruleName.'/i', $rule)) {
				return $rule;
			}
		}

		return false;

	}

	/**
	 * Walk across an array generated by _parse_response, searching for the 'rules'
	 * And return all well-formed rules
	 *
	 * @param array $output
	 * @return array
	 */
	public function parse_processed_output_to_rules_array($output = array())
	{

		// For storing processed rules data
		$rules = Array();

		// For storing raw rules message body
		$body = '';

		// Find rules in the output
		foreach ($output as $value) {
			if (preg_match('/^Rules=/i', $value)) {
				$body = $value;
			}
		}

		// Nothing found
		if (!$body || !is_string($body)) {
			return $rules;
		}

		// Rules may be wrapped with this syntax: Rules=(...) when they
		// come form an EFI query. Lets remove this wrapper if it exists
		if (preg_match('/^Rules=(.+)/', $body, $matches)) {
			$body = $matches[1];
		}

		for ($found = 0, $i = 0; $i < strlen($body); $i++) {
			/** If an opening bracket "(" is found then increase the found paramater */

			if (preg_match('/\(/', $body[$i])) {
				++$found;
			}

			/** If found a closing bracket ")" then subtract the value of found */
			if (preg_match('/\)/', $body[$i])) {
				--$found;
			}

			/**
			 * If we have found two opening brackets and start isn't set meaning this is the first time that
			 * two opening brackets have been found then set start to the current position in the string. Searching
			 * for two open brackets becuase all rules are contained in brackets so the second bracket is the start
			 * of an actual rule.
			 */


			if ($found == 2 && !isset($start)) {
				$start = $i;
			} elseif ($found == 1 && isset($start)) {
				/**
				 * Else if found is down to just one bracket and start as already been set then set the end
				 * variable to the current position of the string. This will correspond to the rules string length.
				 */
				$end = $i;
			}

			/** If the end variable is set then ... */
			if (isset($end)) {
				/** Add the rule to the new rules array */
				$rules[] = substr($body, ($start + 1), ($end - $start - 1));
				/** Unset end and start to start fresh */
				unset($end);
				unset($start);
			}
		}

		/** Strip carriage return and replace new lines with CommuniGate code \e */
		foreach ($rules as $key => $rule) {
			$rules[$key] = str_replace(chr(10), self::API_ESCAPE, $rule);
			$rules[$key] = str_replace("\r", '', $rule);
		}

		// Remove anything that doesnt look like a rule
		$clean_rules = array();
		foreach ($rules as $rule) {
			if (preg_match('/^\d,"#/', $rule)) {
				$clean_rules[] = $rule;
			}
		}

		return $clean_rules;
	}

	/**
	 * Set rule
	 *
	 * This method will set a rule. All rule settings must be passed into the method.
	 *
	 * NOTE: The setting array must be in the following format:
	 * Array(
	 * '#RULE', -- Rule to get, same as the get rule method
	 * '"RULE KEY"', -- The key in the rule to search for
	 * 'RULE_STRUCT' -- The strucutre of the rule, usually contained in a constant.
	 * );
	 *
	 * @param string $domain The domain the account is registerd to
	 * @param string $account The account to set the rule for
	 * @param string $rule The new rule value
	 * @param array $setting An array that contains what rule to look for and the rule strucutre
	 * @return bool success
	 */
	private function set_account_rule($domain, $account, $rule, $setting)
	{

		$this->sendAndParse(str_replace('$account$', $account, str_replace('$domain$', '@' . $domain, self::API_GET_ACCOUNT_EFF_SETTINGS)));

		$current_rules = $this->parse_processed_output_to_rules_array($this->output);

		$rules = '';

		/** If the rules array contains a rule then ... */
		if (count($current_rules) > 0) {
			/** Loop trhough the rules array */
			for ($i = 0; $i < count($current_rules); $i++) {
				/** If the current rule isn't what we are looking for then just add it to the new rules string */
				if (!preg_match('/' . $setting[1] . '/', $current_rules[$i])) {
					$rules .= '(' . $current_rules[$i] . ')';
				} elseif ($rule != '') {
					/**
					 * Else if the new value of the rule is not blank then set the rule found variable to TRUE
					 * and add the rules struct with the new rule value to the new rules string.
					 */
					$rule_found = TRUE;
					$rules .= str_replace('$$', $rule, $setting[2]);
				}

				/** If it's not the last element of the array add a comman to seperate the rules */
				if (($i + 1) != count($current_rules)) {
					$rules .= ',';
				}
			}

			/** If the rules wasn't found and the new value of the rule isn't blank then ... */
			if (!isset($rule_found) && $rule != '') {
				/** If there was other rules then add a comma */
				if ($i != 0) {
					$rules .= ',';
				}
				/** Since the rule didn't already exist then add the rule with the new value to the rules strnig */
				$rules .= str_replace('$$', $rule, $setting[2]);
			}
			/** There is no else because be omitting the rule will delete it. */
		} elseif ($rule != '') {
			/** Else there are no rules set so just add the new one to the rules string */
			$rules = str_replace('$$', $rule, $setting[2]);
		}
		/** There is no else because be omitting the rule will delete it. */

		/** If there is an empty comma at the end of the string then remove it */
		if (preg_match('/,$/', $rules)) {
			$rules = substr($rules, 0, strlen($rules) - 1);
		}

		/** If there is an empty rule at the beginning, remove it */
		if ($rules && $rules[0] == ',') {
			$rules = substr($rules, 1);
		}


		/** If the rules string contains rles then enclose all the rules in brackets */
		if ($rules != '') {
			$rules = '(' . $rules . ')';
		} else /** Else no rules were set so set it to Default = delete setting */ {
			$rules = 'Default';
		}


		/** Setup the command */
		$command = str_replace('$account$', $account . '@' . $domain, self::API_SET_ACCOUNT_RULES);
		$command = str_replace('$rule$', $rules, $command);

		$this->sendAndParse($command);

		return $this->success;
	}


	/**
	 * Set account store
	 *
	 * @param $domain
	 * @param $account
	 * @param int $max_size
	 * @return bool
	 */
	public function set_account_storage($domain, $account, $max_size = 50) {

		if (!preg_match('/m/i', $max_size)) {
			$max_size = "{$max_size}M";
		}

		/** Setup the command */
		$command = str_replace('$account$', $account . '@' . $domain, self::API_UPDATE_ACCOUNT_SETTINGS);
		$command = str_replace('$setting$', '{MaxAccountSize=' . $max_size . ';}', $command);

		$this->sendAndParse($command);
		$this->clearCache();

		return $this->success;
	}

	public function set_account_email_redirect($domain, $account, $email) {
		$email = preg_replace('/,|;/','\e', $email);
		$this->set_account_rule($domain, $account, $email, Array('#redirect', '"Mirror to",', self::API_EMAIL_REDIRECT_STRUCT));
		$this->clearCache();
		return $this->success;
	}

	public function set_account_vacation_message($domain, $account, $message) {
		$this->set_account_rule($domain, $account, $message, Array('#vacation', '"Reply with",', self::API_VACATION_STRUCT));
		$this->clearCache();
		return $this->success;
	}

	public function get_account_email_redirect($domain, $account) {
		$r = $this->get_account_rule($domain, $account, '#redirect');

		if (preg_match('/\("Mirror to","?(.+?)"?\)/i', $r, $matches)) {
			return str_replace('\e', ';', $matches[1]);
		}

		return null;
	}

	public function get_account_vacation_message($domain, $account) {
		$r = $this->get_account_rule($domain, $account, '#vacation');

		if (preg_match('/\("Reply with","?(.+?)"?\),/i', $r, $matches)) {
			return str_replace('\e', chr(10), $matches[1]);
		}

		return null;
	}

	public function clear_account_vacation_message($domain, $account) {
		$this->set_account_vacation_message($domain, $account, '');
		return $this->success;
	}

	public function clear_account_email_redirect($domain, $account) {
		$this->set_account_email_redirect($domain, $account, '');
		return $this->success;
	}

	public function list_lists($domain) {

		$response = $this->send(str_replace('$$', $domain, self::API_LIST_LISTS));
		$this->parse_response($response);

		$lists = substr($response, 5, -3); // Chop off the 200 { whatever }
		$lists = explode(',', $lists);

		return $lists;
	}

	public function create_list($list, $account) {

		$command = str_replace('$list$', $list, self::API_CREATE_LIST);
		$command = str_replace('$account$', $account, $command);

		$this->sendAndParse($command);
		$this->clearCache();

		return $this->success;
	}

	public function get_list($list) {
		
		$response = $this->send(str_replace('$list$', $list, self::API_GET_LIST));
		$this->parse_response($response);

		$list = substr($response, 5, -4); // Chop off the 200 { whatever }
		$list = explode(';', $list);

		return $list;
	}

	public function update_list($list, $settings = []) {

		$command = str_replace('$list$', $list, self::API_UPDATE_LIST);
		
		$defaultSettings = $settings ?: [
			'ArchiveMessageLimit' => '0',
			'ArchiveSizeLimit' => '50M',
			'ArchiveSwapPeriod' =>  '-1',
			'Browse' =>  'nobody',
			'ByeSubject' => '',
			'ByeText' => '',
			'Charset' =>  'utf-8',
			'CheckCharset' =>  'NO',
			'CheckDigestSubject' =>  'YES',
			'CleanupPeriod' =>  '1h',
			'Confirmation' =>  'NO',
			'ConfirmationSubject' => '',
			'ConfirmationText' => '',
			'CoolOffPeriod' =>  '1h',
			'DigestFormat' =>  'plain text',
			'DigestHeader' =>  '',
			'DigestMessageLimit' =>  '100000',
			'DigestPeriod' =>  '100d',
			'DigestSizeLimit' =>  'unlimited',
			'DigestSubject' =>  '',
			'DigestTimeOfDay' =>  '5h',
			'DigestTrailer' =>  '',
			'Distribution' =>  'feed',
			'FailureNotification' =>  'NO',
			'FatalWeight' =>  '10000',
			'FeedHeader' =>  '',
			'FeedPrefixMode' =>  'NO',
			'FeedSubject' =>  '',
			'FeedTrailer' =>  '',
			'FirstModerated' =>  '10001',
			'Format' =>  'anything',
			'HideFromAddress' =>  'NO',
			'KeepToAndCc' =>  'remove',
			'ListFields' =>  '',
			'LogLevel' =>  '0',
			'MaxBounces' =>  '200',
			'OwnerCheck' =>  'IP Addresses',
			'PolicySubject' => '',
			'PolicyText' => '',
			'Postings' =>  'from anybody',
			// 'RealName' => $RealName,
			'Reply' =>  'to Sender',
			'SaveReports' =>  'no',
			'SaveRequests' =>  'no',
			'SizeLimit' =>  'unlimited',
			'Store' =>  'NO',
			'Subscribe' => 'nobody',
			'SupplFields' => '()',
			'TillConfirmed' =>  'NO',
			'TOCLine' =>  '',
			'TOCTrailer' =>  '',
			'UnsubBouncedPeriod' => '7d',
			'WarningSubject' => '',
			'WarningText' => ''
		];

		$ddSettings = '{';
		foreach ($defaultSettings as $key => $value) {
			$ddSettings .= "$key=\"$value\"; ";
		}
		$ddSettings .= '}';

		$command = str_replace('$settings$', $ddSettings, $command);

		$this->sendAndParse($command);
		$this->clearCache();

		return $this->success;

	}

	public function add_list_subscriber($list, $email, $operation = 'FEED', $silently = true, $confirm = false) {
		
		$command = str_replace('$list$', $list, self::API_ADD_LIST_SUBSCRIBER);
		$command = str_replace('$operation$', $operation, $command);
		$command = str_replace('$silently$', ($silently ? 'silently' : ''), $command);
		$command = str_replace('$confirm$', ($confirm ? 'confirm' : ''), $command);
		$command = str_replace('$email$', $email, $command);

		$this->sendAndParse($command);
		$this->clearCache();

		return $this->success;
	}

	/**
	 * $mode = [ UNMODERATED | MODERATEALL | PROHIBITED | SPECIAL | numberOfModerated ]
	 */
	public function set_posting_mode($list, $email, $mode) {

		$command = str_replace('$list$', $list, self::API_SET_POSTING_MODE);
		$command = str_replace('$email$', $email, $command);
		$command = str_replace('$mode$', $mode, $command);

		$this->sendAndParse($command);
		$this->clearCache();

		return $this->success;
	}

	public function list_subscribers($list) {
		
		$command = str_replace('$list$', $list, self::API_LIST_SUBSCRIBERS);
		$this->sendAndParse($command);
		// $response = $this->send($command);
		// $subscribers = $this->parse_response($response);
		// $subscribers = substr($response, 5, -2); // Chop off the 200 { whatever }
		// $subscribers = explode(',', $subscribers);
		return $this->output;
	}

	public function get_subscriber_info($list, $email) {
		
		$command = str_replace('$list$', $list, self::API_GET_SUBSCRIBER_INFO);
		$command = str_replace('$email$', $email, $command);
		
		$this->sendAndParse($command);

		return $this->output;
	}

	public function delete_list($list) {
		
		$command = str_replace('$list$', $list, self::API_DELETE_LIST);
		$this->sendAndParse($command);
		return true;
	}

	/**
	 * Send a command and automatically parse the response
	 *
	 * @param $command
	 * @return bool
	 */
	private function sendAndParse($command) {
		$response = $this->send($command);
		return $this->parse_response($response);
	}

	/**
	 * Clear the request response cache
	 */
	public function clearCache() {
		$this->cache = array();
	}

	/**
	 * Send command
	 *
	 * This method will send a command through the socket
	 * to the CG CLI.
	 *
	 * @param string $command Command sent over socket
	 * @return mixed
	 * @throws ApiException
	 */
	private function send($command)
	{
		if (!$this->connected) {
			$this->connect();
		}

		$hash = md5($command);
		if (array_key_exists($hash, $this->cache)) {
			return $this->cache[$hash];
		}

		// @NOTE This can be a little spammy
		if (!preg_match('/^(USER|PASS|INLINE)/i', $command)) {
			$this->log($command, self::TYPE_SEND);
		}

		fputs($this->socket, $command . chr(10));

        // Sometimes we get a random feof from Communigate
        // Chomp it and try again
		if (feof($this->socket)) {
            fgets($this->socket);
        }
        if (feof($this->socket)) {
			throw new ApiException('CommunigateAPI: Socket terminated early');
		}

        // Use fgets to until end of line
		$this->cache[$hash] = fgets($this->socket);

		$this->log($this->cache[$hash], self::TYPE_RECEIVE);

		return $this->cache[$hash];
	}

	/**
	 * Log a message
	 *
	 * One:
	 *  When verbose mode is enabled, log directly to console.
	 *
	 * Two:
	 *  When Monologger is available, log to monolog object
	 *
	 * @param string $message
	 * @param string $type
	 */
	protected function log($message, $type = null)
	{

		$message = str_replace("\n", '', $message);

		// Make it pretty
		$formatted = sprintf(
			'[Communigate %s] %s',
			$this->config['host'],
			$message
		);

		// For verbose mode
		if ($this->verbose) {
			print($formatted . "\n");
		}

		// For monolog
		if (is_object($this->logger)
			&& method_exists($this->logger, 'info')
			&& $type == self::TYPE_SEND
		) {
			$this->logger->info($formatted);
		}
	}

	/**
	 * @param string $str String to escape
	 * @return strin The escaped string
	 */
	public function escape($str) {
		$str = str_replace('\\', '\\\\', $str);
		$str = str_replace('"', '\"', $str);
		return $str;
	}

	/**
	 * Parse response
	 *
	 * @param string $output Output from the CLI
	 * @return bool
	 * @throws ApiException
	 */
	public function parse_response($output)
	{

		if (!preg_match('/^(\d{3}) (.+)$/', $output, $matches)) {
			throw new ApiException('Malformed response');
		}

		$this->output = '';
		$code = (int)$matches[1];
		$body = (string)$matches[2];

		if (!array_key_exists($code, $this->CGC_KNOWN_SUCCESS_CODES)) {
			$exceptionMessage = sprintf('CGC Error %s - %s',
				$code,
				$body
			);
			throw new ApiException($exceptionMessage, $code);
		} else {
			$this->output = $output;
			$this->_parse_response();
			return $this->success = TRUE;
		}

		return $this->success = FALSE;

	}

	/**
	 * _parse response
	 *
	 * This method will modify the CLI output and create an array of each data item
	 */
	private function _parse_response() {
		/** The exploder will identify how to create the array */
		$exploder = '';

		/** If the command wasn't a successfull then return FALSE */
		if (preg_match('/^201 \{\}/', $this->output) || preg_match('/^201 \(\)/', $this->output)) {
			$this->output = '';
			return $this->success = FALSE;
		}

		// strip out any newline characters
		$this->output = str_replace(["\n", "\r"], '', $this->output);

		/** If the output start with a ( = array format then ... */
		if (preg_match('/^201 \(/', $this->output) && !preg_match('/^201 \(\(/', $this->output)) {
			/** The exploder for the array format is a comma */
			$exploder = ',';
			/** Strip the beginning 201 ( and closing ) */
			$this->output = preg_replace(Array('/^201 \(/', '/\)/'), '', $this->output);
		} elseif (preg_match('/^201 \{/', $this->output)) {
			/** Else the output format is a dictionary , the exploder is a semi-colan */
			$exploder = ';';
			/** Strip the beginning 201 { and closing } */
			$this->output = preg_replace(Array('/^201 \{/', '/\}/'), '', $this->output);
		} elseif (preg_match('/^201 \(\(/', $this->output)) {
			/** The exploder for the array format is a comma */
			$exploder = ',(';
			/** Strip the beginning 201 ( and closing ) */
			$this->output = preg_replace(Array('/^201 \(/', '/\)/'), '', $this->output);
		} else {
			/** Else assume that format is a string or int so explode by the space */
			$exploder = ' ';
			/** Strip the beginning 201 and trim the output */
			$this->output = trim(preg_replace('/^201/', '', $this->output));
		}

		/** Set the output to an array that is exploded by the exploder */
		$this->output = explode($exploder, $this->output);

		/** If the last element of the array is blank then pop it off the array */
		if (strlen(trim($this->output[(count($this->output) - 1)])) == 0) {
			array_pop($this->output);
		}

		return $this->success = TRUE;
	}

}
