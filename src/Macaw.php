<?php

declare(strict_types=1);

namespace NestboxPHP\Macaw;

use NestboxPHP\Macaw\Exception\ApiException;
use PDO;
use stdClass;
use NestboxPHP\Macaw\CatalogTrait;
use NestboxPHP\Nestbox\Nestbox;
use NestboxPHP\Macaw\Exception\MacawException;

class Macaw extends Nestbox
{
    final public const PACKAGE_NAME = 'macaw';
    public const MACAW_LOG_TABLE = 'macaw_api_calls';

    public int $macawStaleHoursNews = 1;
    public int $macawStaleHoursTitleData = 1;
    public int $macawStaleHoursCatalog = 168;
    public int $macawStaleHoursLeaderboard = 24;
    public int $macawClient2MinLimit = 1000;
    public int $macawServer2MinLimit = 12000;
    public bool $macawAutoRefresh = false;
    public string $macawSessionKey = 'playfab';

    protected string $loginMethod = "login_with_email_address";
    protected array $loginOptions = [];
    protected string $titleId = "";

    public function __construct(string $titleId = null, string $host = null, string $user = null, string $pass = null,
                                string $name = null)
    {
        parent::__construct($host, $user, $pass, $name);

        $titleId = trim(string: strval(value: $titleId));

        // define new constants for future calls
        if ($titleId && !defined('MACAW_TITLE_ID')) define('MACAW_TITLE_ID', $titleId);

        // null and undefined values mean missing data
        if (is_null($titleId) && !defined('MACAW_TITLE_ID'))
            throw new MacawException("Missing Macaw \$titleId or `MACAW_TITLE_ID` constant.");

        // manual overrides take precedence for new or invoked instantiations, otherwise use constants
        $this->titleId = ($titleId) ?: MACAW_TITLE_ID;
    }

    public function __invoke(string $titleId = null, string $host = null, string $user = null, string $pass = null,
                             string $name = null): void
    {
        $this->__construct($titleId, $host, $user, $pass, $name);
    }

    use CatalogTrait;

    /**
     * Class Tables
     *   ____ _                 _____     _     _
     *  / ___| | __ _ ___ ___  |_   _|_ _| |__ | | ___  ___
     * | |   | |/ _` / __/ __|   | |/ _` | '_ \| |/ _ \/ __|
     * | |___| | (_| \__ \__ \   | | (_| | |_) | |  __/\__ \
     *  \____|_|\__,_|___/___/   |_|\__,_|_.__/|_|\___||___/
     *
     */

    /**
     * Create macaw_api_log table
     *
     * @return bool
     */
    public function create_class_table_macaw_api_calls(): bool
    {
        if ($this->valid_schema(static::MACAW_LOG_TABLE)) return true;

        $sql = "CREATE TABLE IF NOT EXISTS `" . static::MACAW_LOG_TABLE . "` (
                    `call_id` INT NOT NULL AUTO_INCREMENT ,
                    `call_endpoint` VARCHAR( 64 ) NULL ,
                    `call_client` VARCHAR( 64 ) NULL ,
                    `call_time` DATETIME DEFAULT CURRENT_TIMESTAMP ,
                    `status_code` VARCHAR( 3 ) ,
                    PRIMARY KEY ( `call_id` )
                ) ENGINE = InnoDB DEFAULT CHARSET=UTF8MB4 COLLATE=utf8mb4_general_ci;";
        return $this->query_execute($sql);
    }


    public function create_class_table_macaw_title_news(): bool
    {
        // At the time of development, title and body fields are limited to 10kb within PlayFab
        $sql = "CREATE TABLE IF NOT EXISTS `macaw_title_news` (
                    `news_id` VARCHAR( 36 ) NOT NULL , -- uuid v4
                    `timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ,
                    `title` TEXT , -- maximum 10kb playfab content
                    `body` TEXT , -- maximum 10kb playfab content
                    PRIMARY KEY ( `news_id` )
                ) ENGINE = InnoDB DEFAULT CHARSET=UTF8MB4 COLLATE=utf8mb4_general_ci;";
        return $this->query_execute($sql);
    }


    /**
     * REST Call Handlers
     *  ____  _____ ____ _____    ____      _ _   _   _                 _ _
     * |  _ \| ____/ ___|_   _|  / ___|__ _| | | | | | | __ _ _ __   __| | | ___ _ __ ___
     * | |_) |  _| \___ \ | |   | |   / _` | | | | |_| |/ _` | '_ \ / _` | |/ _ \ '__/ __|
     * |  _ <| |___ ___) || |   | |__| (_| | | | |  _  | (_| | | | | (_| | |  __/ |  \__ \
     * |_| \_\_____|____/ |_|    \____\__,_|_|_| |_| |_|\__,_|_| |_|\__,_|_|\___|_|  |___/
     *
     */

    /**
     * Make a REST call to a given endpoint
     *
     * @param string $endpoint target endpoint
     * @param array $headers request headers
     * @param array $params query parameters
     * @param array $postFields post fields
     * @param string $method request method
     * @param bool $useSessionTicket add the session ticket to the headers
     * @return array
     */
    protected function make_rest_call(string $endpoint, array $headers = [], array $params = [], array $postFields = [],
                                      string $method = "POST", bool $useSessionTicket = true): stdClass
    {
        // API call limiting
        $this->api_call_limiter();

        // input validation
        if (!in_array(needle: "Content-Type: application/json", haystack: $headers)) {
            $headers[] = "Content-Type: application/json";
        }
        if ($useSessionTicket) {
            $headers[] = "X-Authorization: " . $_SESSION[$this->macawSessionKey]["authentication"]->SessionTicket ?? "";
        }

        $params = $this->compile_url_params($params);
        $method = (in_array(strtoupper($method), ["POST", "GET", "PUT", "PATCH", "DELETE"]))
            ? strtoupper($method) : "POST";

        // curl options
        $options = [
            CURLOPT_URL => ("GET" == $method) ? $endpoint . $params : $endpoint,
            CURLOPT_POSTFIELDS => ("POST" == $method) ? json_encode($postFields) : null,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_VERBOSE => true,
            CURLOPT_USERAGENT => "Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1; .NET CLR 1.0.3705; .NET CLR 1.1.4322)",
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADER => false
        ];

        // make the call *epic music starts*
        $curl = curl_init();
        curl_setopt_array($curl, $options);
        $response = json_decode(json: curl_exec($curl) ?: json_encode(["code" => 0]));
        curl_close($curl);

        // log it
        $this->log_api_call(endpoint: $endpoint, statusCode: $response->code ?? 0);

        if (200 != $response->code) {
            var_dump($response);
            throw new ApiException($response);
        }

        return $response->data;

    }

    /**
     * Pause for a number of microseconds based on the current API calls
     *
     * @param bool $isServer use server API limits instead of client API limits
     * @return void
     */
    protected function api_call_limiter(bool $isServer = false): void
    {
        // get total calls per second
        $limitCPS = ($isServer) ? $this->macawServer2MinLimit : $this->macawClient2MinLimit;
        $currentCPS = $this->last_x_minute_calls_per_second(minutes: 2);

        // calculate microsecond values for calls per second
        $limitMicroseconds = 1 / $limitCPS * 1000000;
        $currentMicroseconds = 1 / max($currentCPS, $limitCPS) * 1000000;

        // calculate call to limit ratio and adjust sleep time accordingly
        $callRatio = 1 / ($currentMicroseconds / $limitMicroseconds);
        $sleepTime = intval($limitMicroseconds * $callRatio);

        // developers hate this one weird trick! https://www.php.net/manual/en/function.usleep.php
        usleep(microseconds: $sleepTime);
    }

    /**
     * Log an API call
     *
     * @param string $endpoint call endpoint
     * @param int $statusCode response status code
     * @param bool $isServer api call initiated via a server
     * @return void
     */
    protected function log_api_call(string $endpoint, int $statusCode, bool $isServer = false): int|bool
    {
        $row = [
            "call_endpoint" => preg_replace(pattern: '/^(.*?(?=com))com/', replacement: "", subject: $endpoint),
            "call_client" => ($isServer) ? $_SERVER['SERVER_ADDR'] : $_SERVER['REMOTE_ADDR'],
            "status_code" => $statusCode
        ];

        return $this->insert(static::MACAW_LOG_TABLE, $row);
    }

    /**
     * Gets the API hourly usage from now to `$hoursAgo` grouped by hour
     *
     * @param int $hoursAgo
     * @return array
     */
    public function get_api_call_hourly_usage(int $hoursAgo = 168): array
    {
        $sql = "SELECT
                    FLOOR(TIMESTAMPDIFF(SECOND, `call_time`, NOW()) / 3600) AS 'hours_ago',
                    COUNT(*) AS 'call_count'
                FROM `" . static::MACAW_LOG_TABLE . "`
                WHERE `call_time` >= NOW() - INTERVAL :hours_ago hour
                GROUP BY `hours_ago`;";

        $params = ["hours_ago" => $hoursAgo];
        $results = (!$this->query_execute($sql, $params))
            ? [] : $this->fetch_all_results(fetchMode: PDO::FETCH_KEY_PAIR);

        for ($h = $hoursAgo - 1; $h >= 0; $h--) {
            $results[$h] = ($results[$h] ?? false) ?: 0;
        }

        ksort($results);
        $results = array_reverse($results, true);

        return $results;
    }

    /**
     * Converts an array of key => value pairs to a ?key=value&key=value string
     *
     * @param array $params array of parameters
     * @return string
     */
    protected function compile_url_params(array $params = []): string
    {
        $p = [];
        foreach ($params as $key => $value) {
            $p[] = (is_string($value)) ? "$key=$value" : "$key=" . json_encode($value);
        }
        return (!$p) ? "" : "?" . implode("&", $p);
    }

    /**
     * Returns the calls-per-second rate for the last x minutes
     *
     * @param int $minutes
     * @return int
     */
    public function last_x_minute_calls_per_second(int $minutes): int
    {
        $sql = "SELECT
                    IFNULL(
                        COUNT(*)
                        DIV TIMESTAMPDIFF(
                            SECOND
                            , CURRENT_TIMESTAMP - INTERVAL $minutes MINUTE
                            , CURRENT_TIMESTAMP
                        )
                        , 0
                    ) AS `calls_per_second`
                FROM `macaw_api_calls`
                WHERE `call_time` > (CURRENT_TIMESTAMP - INTERVAL $minutes MINUTE);";

        if (!$this->query_execute($sql)) {
            return 0;
        }

        return intval($this->fetch_first_result()["calls_per_second"]);
    }


    public function last_endpoint_call(string $endpoint, string $statusCode = null): string
    {
        $statusCode = ($statusCode) ?: "%";
        $sql = "SELECT `call_time` FROM `macaw_api_calls`
                WHERE
                    `call_endpoint` LIKE :call_endpoint
                    AND `status_code` LIKE :status_code
                ORDER BY `call_time` DESC LIMIT 1;";

        $params = [
            "call_endpoint" => "$endpoint%",
            "status_code" => $statusCode
        ];

        if (!$this->query_execute($sql, $params)) return "";
        if (!$results = $this->fetch_first_result()) return "";
        return $results["call_time"];
    }


    public function hours_since_last_api_call_to_endpoint(string $endpoint, string $statusCode = null): int
    {
        $statusCode = ($statusCode) ?: "%";
        $sql = "SELECT TIMESTAMPDIFF(HOUR, `call_time`, NOW()) AS 'hours_since_last_call'
                FROM `macaw_api_calls`
                WHERE
                    `call_endpoint` LIKE :call_endpoint
                    AND `status_code` LIKE :status_code
                ORDER BY `hours_since_last_call` ASC LIMIT 1;";

        $params = [
            "call_endpoint" => "$endpoint%",
            "status_code" => $statusCode
        ];

        if (!$this->query_execute($sql, $params)) return 0;

        $results = $this->fetch_first_result();
        if (!$results) return 0;
        return $results["hours_since_last_call"];
    }


    /**
     * Response Handlers
     *  ____                                        _   _                 _ _
     * |  _ \ ___  ___ _ __   ___  _ __  ___  ___  | | | | __ _ _ __   __| | | ___ _ __ ___
     * | |_) / _ \/ __| '_ \ / _ \| '_ \/ __|/ _ \ | |_| |/ _` | '_ \ / _` | |/ _ \ '__/ __|
     * |  _ <  __/\__ \ |_) | (_) | | | \__ \  __/ |  _  | (_| | | | | (_| | |  __/ |  \__ \
     * |_| \_\___||___/ .__/ \___/|_| |_|___/\___| |_| |_|\__,_|_| |_|\__,_|_|\___|_|  |___/
     *                |_|
     */

    /**
     * Data Ingestion
     *  ____        _          ___                       _   _
     * |  _ \  __ _| |_ __ _  |_ _|_ __   __ _  ___  ___| |_(_) ___  _ __
     * | | | |/ _` | __/ _` |  | || '_ \ / _` |/ _ \/ __| __| |/ _ \| '_ \
     * | |_| | (_| | || (_| |  | || | | | (_| |  __/\__ \ |_| | (_) | | | |
     * |____/ \__,_|\__\__,_| |___|_| |_|\__, |\___||___/\__|_|\___/|_| |_|
     *                                   |___/
     */


    protected function ingest_data(stdClass $data, string $tablePrefix): void
    {
        // parse table structure
        $structure = $this->parse_table_structure($data, $tablePrefix);

        // create tables
        if (!$this->create_table_from_structure($structure))
            throw new MacawException("Failed to create title_data table.");

        // save json contents to queue directory
        foreach ($this->compile_table_data_from_object($data, $tablePrefix) as $tableName => $tableRows) {
            $this->save_json_to_ingest_directory([$tableName => $tableRows]);
        }

        // process ingest queue according to time
        $this->process_ingest_queue();
    }

    /**
     * Parse response object to determine table structure
     *
     * @param stdClass $data
     * @return array
     */
    protected function parse_table_structure(stdClass $data, string $tablePrefix): array
    {
        $tableData = [];
        foreach ($data as $tableName => $datum) {
            $datum = (is_string($datum)) ? json_decode($datum) : $datum;
            $tableKeys = ["RowId" => ["type" => null, "max_length" => 0]];
            foreach ($datum as $itemId => $rowData) {
                $tableKeys["RowId"]["type"] = gettype($itemId);
                $tableKeys["RowId"]["max_length"] = max($tableKeys["RowId"]["max_length"], strlen($itemId));
                foreach ($rowData as $column => $value) {
                    if (!array_key_exists($column, $tableKeys)) {
                        $tableKeys[$column] = ["type" => null, "max_length" => 0];
                    }
                    $tableKeys[$column]["type"] = gettype($value);
                    $tableKeys[$column]["max_length"] = max($tableKeys[$column]["max_length"], strlen("$value"));
                }
            }
            $tableData[strtolower($tablePrefix . $tableName)] = $tableKeys;
        }

        return $tableData;
    }


    protected function create_table_from_structure(array $structure, bool $dropTable = true): true
    {
        foreach ($structure as $tableName => $columns) {
            // table creation
            if (!$this->valid_schema($tableName)) {
                $sql = ["CREATE TABLE IF NOT EXISTS `$tableName` ("];
                $cols = [];
                foreach ($columns as $columnName => $metadata) {
                    $primaryKey = ("RowId" == $columnName) ? "PRIMARY KEY NOT NULL" : "";
                    $cols[] = "\t`$columnName` VARCHAR( {$metadata['max_length']} ) $primaryKey";
                }
                $cols = implode(",\n", $cols);
                $sql = "CREATE TABLE IF NOT EXISTS `$tableName` (
                            $cols
                        ) ENGINE = InnoDB DEFAULT CHARSET=UTF8MB4 COLLATE=utf8mb4_general_ci;";
                if (!$this->query_execute($sql))
                    throw new MacawException("Failed to create title_data table.");
            }
        }

        return true;
    }


    protected function deprecated_save_import_json_in_queue_directory(string $filename, stdClass|array|string $data): true
    {
        if (!str_ends_with(haystack: $filename, needle: ".json")) $filename .= ".json";
        $fullpath = $this->generate_document_root_relative_path([static::MACAW_INGEST_DIRECTORY, $filename]);
        $data = (!is_string($data)) ? json_encode($data) : $data;
        file_put_contents(filename: $filename, data: $data);
    }


    public function deprecated_insert_table_data(stdClass $data, string $tablePrefix): int
    {
        set_time_limit(300);

        $structure = $this->parse_table_structure($data, $tablePrefix);

        if (!$this->create_table_from_structure($structure))
            throw new MacawException("Failed to create title_data table.");

        $this->load_table_schema(true);
        $tableData = $this->compile_table_data_from_object($data, $tablePrefix);

        foreach ($tableData as $tableName => $rows) {
            $rowChunks = array_chunk($rows, 3500);
            foreach ($rowChunks as $rows) {
                $this->insert(table: $tableName, rows: $rows);
            }
        }
    }


    protected function compile_table_data_from_object(stdClass $data, string $tablePrefix): array
    {
        $tableData = [];

        foreach ($data as $tableName => $rowData) {
            $tableName = strtolower($tablePrefix . $tableName);
            $rows = [];
            $rowData = (is_string($rowData)) ? json_decode($rowData, associative: true) : $rowData;
            foreach ($rowData as $rowId => $columns) {
                $defaultColumns = array_combine(
                    keys: array_keys($this->tableSchema[$tableName]),
                    values: array_fill(0, count($this->tableSchema[$tableName]), "")
                );
                $defaultColumns["RowId"] = $rowId;
                foreach ($columns as $column => $value) $defaultColumns[$column] = $value;
                $rows[] = $defaultColumns;
            }
            $tableData[strtolower($tableName)] = $rows;
        }

        return $tableData;
    }


    /**
     * Session Tickets
     *  ____                _               _____ _      _        _
     * / ___|  ___  ___ ___(_) ___  _ __   |_   _(_) ___| | _____| |_ ___
     * \___ \ / _ \/ __/ __| |/ _ \| '_ \    | | | |/ __| |/ / _ \ __/ __|
     *  ___) |  __/\__ \__ \ | (_) | | | |   | | | | (__|   <  __/ |_\__ \
     * |____/ \___||___/___/_|\___/|_| |_|   |_| |_|\___|_|\_\___|\__|___/
     *
     */

    /**
     * Gets the session ticket, or forces a new one to be generated
     *
     * @param bool $forceRefresh
     * @return string
     */
    public function get_session_ticket(bool $forceRefresh = false): string
    {
        if ($this->session_ticket_is_expired() || $forceRefresh) {
            $this->relogin_user($this->loginMethod, $this->loginOptions);
        }

        return $_SESSION[$this->macawSessionKey]["data"]["SessionTicket"];
    }

    /**
     * Returns `true` if the session ticket is expired, otherwise `false`
     *
     * @return bool
     */
    public function session_ticket_is_expired(): bool
    {
        $expired = strtotime(datetime: $_SESSION["PlayFab"]->EntityToken->ToeknExpiration ?? "now");
        $rightNow = strtotime(gmdate(format: "Y-m-d\TH:i:s\Z"));

        return $rightNow >= $expired;
    }


    /**
     * Authentication APIs
     *     _         _   _                _   _           _   _
     *    / \  _   _| |_| |__   ___ _ __ | |_(_) ___ __ _| |_(_) ___  _ __
     *   / _ \| | | | __| '_ \ / _ \ '_ \| __| |/ __/ _` | __| |/ _ \| '_ \
     *  / ___ \ |_| | |_| | | |  __/ | | | |_| | (_| (_| | |_| | (_) | | | |
     * /_/   \_\__,_|\__|_| |_|\___|_| |_|\__|_|\___\__,_|\__|_|\___/|_| |_|
     *
     *
     * https://learn.microsoft.com/en-us/rest/api/playfab/client/authentication
     */

    /**
     * Re-authenticates a user session based on previous login credentials.
     *
     * @param string $loginMethod
     * @param array $loginOptions
     * @return array
     */
    public function relogin_user(string $loginMethod, array $loginOptions): array
    {
        if ("login_with_email_address" == $loginMethod) {
            $response = $this->login_with_email_address(
                email: $loginOptions["email"] ?? "",
                password: $loginOptions["password"] ?? "",
                customTags: $loginOptions["customTags"] ?? [],
                infoRequestParameters: $loginOptions["infoRequestParameters"] ?? []
            );
        }

        if ("login_with_google_account" == $loginMethod) {
            $response = $this->login_with_google_account(
                createAccount: $loginOptions["createAccount"] ?? false,
                customTags: $loginOptions["customTags"] ?? [],
                encryptedRequest: $loginOptions["encryptedRequest"] ?? "",
                infoRequestParameters: $loginOptions["infoRequestParameters"] ?? [],
                playerSecret: $loginOptions["playerSecret"] ?? "",
                serverAuthCode: $loginOptions["serverAuthCode"] ?? "",
                setEmail: $loginOptions["setEmail"] ?? true,
            );
        }

        return $response ?? [];
    }

    /**
     * Returns the title's base 64 encoded RSA CSP blob.
     *
     * @param string $titleSharedSecret The shared secret key for this title
     * @return array
     */
    public function get_title_public_key(string $titleSharedSecret): array
    {
        $postFields = [
            "TitleId" => $this->titleId,
            "TitleSharedSecret" => $titleSharedSecret
        ];

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/GetTitlePublicKey",
            postFields: $postFields);
    }

    /**
     * Registers a new Playfab user account, returning a session identifier that can subsequently be used for API calls
     *  which require an authenticated user. You must supply a username and an email address.
     *
     * @param string $username
     * @param string $email
     * @param string $password
     * @return array response
     */
    public function register_playfab_user(string $username, string $email, string $password): array
    {
        $postFields = [
            "TitleId" => $this->titleId,
            "Username" => $username,
            "Email" => $email,
            "Password" => $password
        ];

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/RegisterPlayFabUser",
            postFields: $postFields, useSessionTicket: false);
    }

    /**
     * Signs the user into the PlayFab account, returning a session identifier that can subsequently be used for API
     * calls which require an authenticated user. Unlike most other login API calls, LoginWithEmailAddress does not
     * permit the creation of new accounts via the CreateAccountFlag. Email addresses may be used to create accounts via
     * RegisterPlayFabUser.
     *
     * @param string $email Email address for the account.
     * @param string $password Password for the PlayFab account (6-100 characters)
     * @param array $customTags The optional custom tags associated with the request (e.g. build number, external trace
     * identifiers, etc.).
     * @param array $infoRequestParameters Flags for which pieces of info to return for the user.
     * @return array
     */
    public function login_with_email_address(string $email, string $password, array $customTags = [],
                                             array  $infoRequestParameters = []): stdClass
    {
        $this->loginMethod = "login_with_email_address";
        $this->loginOptions = [
            "Email" => $email,
            "Password" => $password,
            "CustomTags" => $customTags,
            "InfoRequestParameters" => $infoRequestParameters,
        ];

        $postFields = [
            "Email" => $email,
            "Password" => $password,
            "TitleId" => $this->titleId
        ];
        if ($customTags) $postFields["CustomTags"] = $customTags;
        if ($infoRequestParameters) $postFields["InfoRequestParameters"] = $infoRequestParameters;

        $response = $this->make_rest_call(
            endpoint: "https://$this->titleId.playfabapi.com/Client/LoginWithEmailAddress",
            postFields: $postFields,
            useSessionTicket: false
        );

        $_SESSION[$this->macawSessionKey]["authentication"] = $response;

        return $response;
    }

    /**
     * Signs the user in using their Google account credentials
     *
     * @param bool $createAccount Automatically create a PlayFab account if one is not currently linked to this ID.
     * @param array $customTags The optional custom tags associated with the request (e.g. build number, external trace
     * identifiers, etc.).
     * @param string $encryptedRequest Base64 encoded body that is encrypted with the Title's public RSA key (Enterprise
     * Only).
     * @param array $infoRequestParameters Flags for which pieces of info to return for the user.
     * @param string $playerSecret Player secret that is used to verify API request signatures (Enterprise Only).
     * @param string $serverAuthCode OAuth 2.0 server authentication code obtained on the client by calling the
     * getServerAuthCode() Google client API.
     * @param bool $setEmail Optional boolean to opt out of setting the MPA email when creating a Google account,
     * defaults to true.
     * @return array
     */
    public function login_with_google_account(bool   $createAccount = false, array $customTags = [],
                                              string $encryptedRequest = "", array $infoRequestParameters = [],
                                              string $playerSecret = "", string $serverAuthCode = "",
                                              bool   $setEmail = true): array
    {
        $this->loginMethod = "login_with_google_account";
        $this->loginOptions = [
            "createAccount" => $createAccount,
            "customTags" => $customTags,
            "encryptedRequest" => $encryptedRequest,
            "infoRequestParameters" => $infoRequestParameters,
            "playerSecret" => $playerSecret,
            "serverAuthCode" => $serverAuthCode,
            "setEmail" => $setEmail,
        ];

        $postFields = [
            "TitleId" => $this->titleId,
            "CreateAccount" => $createAccount,
            "CustomTags" => $customTags,
            "SetEmail" => $setEmail
        ];

        if ($encryptedRequest) $postFields["EncryptedRequest"] = $encryptedRequest;
        if ($infoRequestParameters) $postFields["InfoRequestParameters"] = $infoRequestParameters;
        if ($playerSecret) $postFields["PlayerSecret"] = $playerSecret;
        if ($serverAuthCode) $postFields["ServerAuthCode"] = $serverAuthCode;

        $response = $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/LoginWithGoogleAccount",
            postFields: $postFields, useSessionTicket: false);

        $_SESSION[$this->macawSessionKey]["data"] = $response["data"] ?? [];

        return $response;
    }


    /**
     * Method to exchange a legacy AuthenticationTicket or title SecretKey for an Entity Token or to refresh a still
     * valid Entity Token.
     *
     * @return stdClass
     */
    public function get_entity_token(): stdClass
    {
        $response = $this->make_rest_call(
            endpoint: "https://$this->titleId.playfabapi.com/Authentication/GetEntityToken");

        $_SESSION[$this->macawSessionKey]["entity_token"] = $response;
        return $response;
    }

    public function validate_entity_token(string $entityToken): stdClass
    {
        $headers = ["X-EntityToken" => $entityToken];
        $postFields = ["EntityToken" => $_SESSION["entity_token"]];
        return $this->make_rest_call(
            endpoint: "https://$this->titleId.playfabapi.com/Authentication/ValidateEntityToken",
            headers: $headers,
            postFields: $postFields,
            useSessionTicket: true);
    }


    /**
     * Character Data APIs
     *   ____ _                          _              ____        _
     *  / ___| |__   __ _ _ __ __ _  ___| |_ ___ _ __  |  _ \  __ _| |_ __ _
     * | |   | '_ \ / _` | '__/ _` |/ __| __/ _ \ '__| | | | |/ _` | __/ _` |
     * | |___| | | | (_| | | | (_| | (__| ||  __/ |    | |_| | (_| | || (_| |
     *  \____|_| |_|\__,_|_|  \__,_|\___|\__\___|_|    |____/ \__,_|\__\__,_|
     *
     *
     * https://learn.microsoft.com/en-us/rest/api/playfab/client/character-data
     */

    /**
     * Retrieves the title-specific custom data for the character which is readable and writable by the client
     *
     * @param string $characterId Unique PlayFab assigned ID for a specific character owned by a user
     * @param int $ifChangedFromDataVersion The version that currently exists according to the caller. The call will
     * return the data for all of the keys if the version in the system is greater than this.
     * @param array $keys Specific keys to search for in the custom user data.
     * @param string|null $playFabId Unique PlayFab identifier of the user to load data for. Optional, defaults to
     * yourself if not set.
     * @return array API response
     */
    public function get_character_data(string $characterId, int $ifChangedFromDataVersion = 0, array $keys = [],
                                       string $playFabId = null): array
    {
        $postFields = ["CharacterId" => $characterId];
        if (0 < $ifChangedFromDataVersion) $postFields["ifChangedFromDataVersion"] = $ifChangedFromDataVersion;
        if ($keys) $postFields["Keys"] = $keys;
        if ($playFabId) $postFields["PlayFabId"] = $playFabId;

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/GetCharacterData",
            postFields: $postFields);
    }

    /**
     * Retrieves the title-specific custom data for the character which can only be read by the client
     *
     * @param string $characterId Unique PlayFab assigned ID for a specific character owned by a user
     * @param int $ifChangedFromDataVersion The version that currently exists according to the caller. The call will
     * return the data for all of the keys if the version in the system is greater than this.
     * @param array $keys Specific keys to search for in the custom user data.
     * @param string|null $playFabId Unique PlayFab identifier of the user to load data for. Optional, defaults to
     * yourself if not set.
     * @return array API response
     */
    public function get_character_read_only_data(string $characterId, int $ifChangedFromDataVersion = 0,
                                                 array  $keys = [], string $playFabId = null): array
    {
        $postFields = ["CharacterId" => $characterId];
        if (0 < $ifChangedFromDataVersion) $postFields["ifChangedFromDataVersion"] = $ifChangedFromDataVersion;
        if ($keys) $postFields["Keys"] = $keys;
        if ($playFabId) $postFields["PlayFabId"] = $playFabId;

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/GetCharacterReadOnlyData",
            postFields: $postFields);
    }

    /**
     * Creates and updates the title-specific custom data for the user's character which is readable and writable by the
     * client
     *
     * @param string $characterId Unique PlayFab assigned ID for a specific character owned by a user
     * @param array $data Key-value pairs to be written to the custom data. Note that keys are trimmed of whitespace,
     * are limited in size, and may not begin with a '!' character or be null.
     * @param array $customTags The optional custom tags associated with the request (e.g. build number, external trace
     * identifiers, etc.).
     * @param array $keysToRemove Optional list of Data-keys to remove from UserData. Some SDKs cannot insert null-
     * values into Data due to language constraints. Use this to delete the keys directly.
     * @param bool $isPublic Permission to be applied to all user data keys written in this request. Defaults to
     * "private" if not set.
     * @return array
     */
    public function update_character_data(string $characterId, array $data, array $customTags = [],
                                          array  $keysToRemove = [], bool $isPublic = False): array
    {
        $postFields = [
            "CharacterId" => $characterId,
            "Data" => $data,
            "Permission" => ($isPublic) ? "Public" : "Private"
        ];
        if ($customTags) $postFields["CustomTags"] = $customTags;
        if ($keysToRemove) $postFields["KeysToRemove"] = $keysToRemove;

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/UpdateCharacterData",
            postFields: $postFields);
    }

    /**
     * Characters APIs
     *   ____ _                          _
     *  / ___| |__   __ _ _ __ __ _  ___| |_ ___ _ __ ___
     * | |   | '_ \ / _` | '__/ _` |/ __| __/ _ \ '__/ __|
     * | |___| | | | (_| | | | (_| | (__| ||  __/ |  \__ \
     *  \____|_| |_|\__,_|_|  \__,_|\___|\__\___|_|  |___/
     *
     *
     * https://learn.microsoft.com/en-us/rest/api/playfab/client/characters
     */

    /**
     * Lists all of the characters that belong to a specific user. CharacterIds are not globally unique; characterId
     * must be evaluated with the parent PlayFabId to guarantee uniqueness.
     *
     * @param string $playFabId
     * @return array Unique PlayFab assigned ID of the user on whom the operation will be performed.
     */
    public function get_all_users_characters(string $playFabId): array
    {
        $postFields = ["PlayFabId" => $playFabId];
        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/GetAllUsersCharacters",
            postFields: $postFields);
    }

    /**
     * Retrieves a list of ranked characters for the given statistic, starting from the indicated point in the
     * leaderboard
     *
     * @param int $startPosition First entry in the leaderboard to be retrieved.
     * @param string $statisticName Unique identifier for the title-specific statistic for the leaderboard.
     * @param int $maxResultsCount Maximum number of entries to retrieve. Default 10, maximum 100.
     * @return array
     */
    public function get_character_leaderboard(int $startPosition, string $statisticName,
                                              int $maxResultsCount = 10): array
    {
        $postFields = [
            "StartPosition" => $startPosition,
            "StatisticName" => $statisticName,
            "MaxResultsCount" => max(10, min($maxResultsCount, 100))
        ];

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/GetCharacterLeaderboard",
            postFields: $postFields);
    }

    /**
     * Retrieves the details of all title-specific statistics for the user
     *
     * @param string $characterId Unique PlayFab assigned ID for a specific character owned by a user
     * @return array
     */
    public function get_character_statistics(string $characterId): array
    {
        $postFields = ["CharacterId" => $characterId];
        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/GetCharacterStatistics",
            postFields: $postFields);
    }

    /**
     * Retrieves a list of ranked characters for the given statistic, centered on the requested Character ID
     *
     * @param string $characterId Unique PlayFab assigned ID for a specific character on which to center the
     * leaderboard.
     * @param string $statisticName Unique PlayFab assigned ID for a specific character on which to center the
     * leaderboard.
     * @param int $maxResultsCount Maximum number of entries to retrieve. Default 10, maximum 100.
     * @return array
     */
    public function get_leaderboard_around_character(string $characterId, string $statisticName,
                                                     int    $maxResultsCount = 10): array
    {
        $postFields = [
            "CharacterId" => $characterId,
            "StatisticName" => $statisticName,
            "MaxResultsCount" => max(10, min($maxResultsCount, 100))
        ];
        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/GetLeaderboardAroundCharacter",
            postFields: $postFields);
    }

    /**
     * Retrieves a list of all of the user's characters for the given statistic.
     *
     * @param string $statisticName Unique identifier for the title-specific statistic for the leaderboard.
     * @return array
     */
    public function get_leaderboard_for_user_characters(string $statisticName): array
    {
        $postFields = ["StatisticName" => $statisticName];
        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/GetLeaderboardForUserCharacters",
            postFields: $postFields);
    }

    /**
     * Grants the specified character type to the user. CharacterIds are not globally unique; characterId must be
     * evaluated with the parent PlayFabId to guarantee uniqueness.
     *
     * @param string $characterName Non-unique display name of the character being granted (1-40 characters in length).
     * @param string $itemId Catalog item identifier of the item in the user's inventory that corresponds to the
     * character in the catalog to be created.
     * @param string $catalogVersion Catalog version from which items are to be granted.
     * @param array $customTags The optional custom tags associated with the request (e.g. build number, external trace
     * identifiers, etc.).
     * @return array
     */
    public function grant_character_to_user(string $characterName, string $itemId, string $catalogVersion = "",
                                            array  $customTags = []): array
    {
        $postFields = [
            "CharacterName" => $characterName,
            "ItemId" => $itemId
        ];
        if ($catalogVersion) $postFields["CatalogVersion"] = $catalogVersion;
        if ($customTags) $postFields["CustomTags"] = $customTags;
        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/GrantCharacterToUser",
            postFields: $postFields);
    }

    /**
     * Updates the values of the specified title-specific statistics for the specific character. By default, clients are
     * not permitted to update statistics. Developers may override this setting in the Game Manager > Settings > API
     * Features.
     *
     * @param string $characterId Unique PlayFab assigned ID for a specific character owned by a user
     * @param array $characterStatistics Statistics to be updated with the provided values, in the Key(string),
     * Value(int) pattern.
     * @param array $customTags The optional custom tags associated with the request (e.g. build number, external trace
     * identifiers, etc.).
     * @return array
     */
    public function update_character_statistics(string $characterId, array $characterStatistics = [],
                                                array  $customTags = []): array
    {
        $postFields = ["CharacterId" => $characterId];
        if ($characterStatistics) $postFields["CharacterStatistics"] = $characterStatistics;
        if ($customTags) $postFields["CustomTags"] = $customTags;
        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/UpdateCharacterStatistics",
            postFields: $postFields);
    }


    /**
     * Content Service APIs
     *   ____            _             _
     *  / ___|___  _ __ | |_ ___ _ __ | |_
     * | |   / _ \| '_ \| __/ _ \ '_ \| __|
     * | |__| (_) | | | | ||  __/ | | | |_
     *  \____\___/|_| |_|\__\___|_| |_|\__|
     *
     *
     * https://learn.microsoft.com/en-us/rest/api/playfab/client/content
     */

    /**
     * This API retrieves a pre-signed URL for accessing a content file for the title. A subsequent HTTP GET to the
     * returned URL will attempt to download the content. A HEAD query to the returned URL will attempt to retrieve the
     * metadata of the content. Note that a successful result does not guarantee the existence of this content - if it
     * has not been uploaded, the query to retrieve the data will fail.
     *
     * @param string $key Key of the content item to fetch, usually formatted as a path, e.g. images/a.png
     * @param string $httpMethod HTTP method to fetch item - GET or HEAD. Use HEAD when only fetching metadata. Default
     * is GET.
     * @param bool $thruCDN True to download through CDN. CDN provides higher download bandwidth and lower latency.
     * However, if you want the latest, non-cached version of the content during development, set this to false.
     * Default is true.
     * @return array
     */
    public function get_content_download_url(string $key, string $httpMethod = "GET", bool $thruCDN = true): array
    {
        $postFields = [
            "Key" => $key,
            "HttpMethod" => (in_array(needle: strtoupper($httpMethod), haystack: ["GET", "HEAD"]))
                ? strtoupper($httpMethod) : "GET",
            "ThruCDN" => $thruCDN
        ];

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/GetContentDownloadUrl",
            postFields: $postFields);
    }


    /**
     * Friend List Management APIs
     *  _____     _                _       _     _     _     __  __                                                   _
     * |  ___| __(_) ___ _ __   __| |___  | |   (_)___| |_  |  \/  | __ _ _ __   __ _  __ _  ___ _ __ ___   ___ _ __ | |_
     * | |_ | '__| |/ _ \ '_ \ / _` / __| | |   | / __| __| | |\/| |/ _` | '_ \ / _` |/ _` |/ _ \ '_ ` _ \ / _ \ '_ \| __|
     * |  _|| |  | |  __/ | | | (_| \__ \ | |___| \__ \ |_  | |  | | (_| | | | | (_| | (_| |  __/ | | | | |  __/ | | | |_
     * |_|  |_|  |_|\___|_| |_|\__,_|___/ |_____|_|___/\__| |_|  |_|\__,_|_| |_|\__,_|\__, |\___|_| |_| |_|\___|_| |_|\__|
     *                                                                                |___/
     *
     * https://learn.microsoft.com/en-us/rest/api/playfab/client/friend-list-management
     */

    /**
     * Adds the PlayFab user, based upon a match against a supplied unique identifier, to the friend list of the local
     * user. At least one of FriendPlayFabId, FriendUsername, FriendEmail, or FriendTitleDisplayName should be
     * initialized.
     *
     * @param string|null $email Email address of the user to attempt to add to the local user's friend list.
     * @param string|null $playFabId PlayFab identifier of the user to attempt to add to the local user's friend list.
     * @param string|null $titleDisplayName Title-specific display name of the user to attempt to add to the local
     * user's friend list.
     * @param string|null $username PlayFab username of the user to attempt to add to the local user's friend list.
     * @return array
     */
    public function add_friend(string $email = null, string $playFabId = null, string $titleDisplayName = null,
                               string $username = null): array
    {
        $postFields = [];

        if ($email) $postFields["FriendEmail"] = $email;
        if ($playFabId) $postFields["FriendPlayFabId"] = $playFabId;
        if ($titleDisplayName) $postFields["FriendTitleDisplayName"] = $titleDisplayName;
        if ($username) $postFields["FriendUsername"] = $username;

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/AddFriend",
            postFields: $postFields);
    }

    /**
     * Retrieves the current friend list for the local user, constrained to users who have PlayFab accounts. Friends
     * from linked accounts (Facebook, Steam) are also included. You may optionally exclude some linked services'
     * friends.
     *
     * @param array $customTags The optional custom tags associated with the request (e.g. build number, external trace
     * identifiers, etc.).
     * @param array $externalPlatformFriends Indicates which other platforms' friends should be included in the
     * response. In HTTP, it is represented as a comma-separated list of platforms.
     * @param array $profileConstraints If non-null, this determines which properties of the resulting player profiles
     * to return. For API calls from the client, only the allowed client profile properties for the title may be
     * requested. These allowed properties are configured in the Game Manager "Client Profile Options" tab in the
     * "Settings" section.
     * @param string $xboxToken Xbox token if Xbox friends should be included. Requires Xbox be configured on PlayFab.
     * @return array
     */
    public function get_friends_list(array $customTags = [], array $externalPlatformFriends = [],
                                     array $profileConstraints = [], string $xboxToken = ""): array
    {
        $postFields = [];

        if ($customTags) $postFields["CustomTags"] = $customTags;
        if ($externalPlatformFriends) $postFields["ExternalPlatformFriends"] = $externalPlatformFriends;
        if ($profileConstraints) $postFields["ProfileConstraints"] = $profileConstraints;
        if ($xboxToken) $postFields["XboxToken"] = $xboxToken;

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/GetFriendsList",
            postFields: $postFields);
    }

    /**
     * Removes a specified user from the friend list of the local user
     *
     * @param string $friendPlayFabId PlayFab identifier of the friend account which is to be removed.
     * @return array
     */
    public function remove_friend(string $friendPlayFabId): array
    {
        $postFields = ["FriendPlayFabId" => $friendPlayFabId];

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/RemoveFriend",
            postFields: $postFields);
    }

    /**
     * Updates the tag list for a specified user in the friend list of the local user
     *
     * @param string $playFabId PlayFab identifier of the friend account to which the tag(s) should be applied.
     * @param array $tags Array of tags to set on the friend account.
     * @return array
     */
    public function set_friend_tags(string $playFabId, array $tags = []): array
    {
        $postFields = [
            "FriendPlayFabId" => $playFabId,
            "Tags" => $tags
        ];

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/SetFriendTags",
            postFields: $postFields);
    }


    /**
     * Player Data Management APIs
     *  ____  _                         ____        _          __  __                                                   _
     * |  _ \| | __ _ _   _  ___ _ __  |  _ \  __ _| |_ __ _  |  \/  | __ _ _ __   __ _  __ _  ___ _ __ ___   ___ _ __ | |_
     * | |_) | |/ _` | | | |/ _ \ '__| | | | |/ _` | __/ _` | | |\/| |/ _` | '_ \ / _` |/ _` |/ _ \ '_ ` _ \ / _ \ '_ \| __|
     * |  __/| | (_| | |_| |  __/ |    | |_| | (_| | || (_| | | |  | | (_| | | | | (_| | (_| |  __/ | | | | |  __/ | | | |_
     * |_|   |_|\__,_|\__, |\___|_|    |____/ \__,_|\__\__,_| |_|  |_|\__,_|_| |_|\__,_|\__, |\___|_| |_| |_|\___|_| |_|\__|
     *                |___/                                                             |___/
     *
     * https://learn.microsoft.com/en-us/rest/api/playfab/client/player-data-management
     */

    /**
     * Retrieves a list of ranked friends of the current player for the given statistic, starting from the indicated
     * point in the leaderboard
     *
     * @param int $startPosition Position in the leaderboard to start this listing (defaults to the first entry).
     * @param string $statisticName Statistic used to rank friends for this leaderboard.
     * @param array $customTags The optional custom tags associated with the request (e.g. build number, external trace
     * identifiers, etc.).
     * @param array $externalPlatformFriends Indicates which other platforms' friends should be included in the
     * response. In HTTP, it is represented as a comma-separated list of platforms.
     * @param int $maxResultsCount Maximum number of entries to retrieve. Default 10, maximum 100.
     * @param array $profileConstraints If non-null, this determines which properties of the resulting player profiles
     * to return. For API calls from the client, only the allowed client profile properties for the title may be
     * requested. These allowed properties are configured in the Game Manager "Client Profile Options" tab in the
     * "Settings" section.
     * @param bool $useSpecificVersion If set to false, Version is considered null. If true, uses the specified Version
     * @param int|null $version The version of the leaderboard to get.
     * @param string $xboxToken Xbox token if Xbox friends should be included. Requires Xbox be configured on PlayFab.
     * @return array
     */
    public function get_friend_leaderboard(int   $startPosition, string $statisticName, array $customTags = [],
                                           array $externalPlatformFriends = [], int $maxResultsCount = 10,
                                           array $profileConstraints = [], bool $useSpecificVersion = false,
                                           int   $version = null, string $xboxToken = ""): array
    {
        $postFields = [
            "StartPosition" => $startPosition,
            "StatisticName" => $statisticName,
            "MaxResultsCount" => max(10, min($maxResultsCount, 100))
        ];
        if ($customTags) $postFields["CustomTags"] = $customTags;
        if ($externalPlatformFriends) $postFields["ExternalPlatformFriends"] = $externalPlatformFriends;
        if ($profileConstraints) $postFields["ProfileConstraints"] = $profileConstraints;
        if ($useSpecificVersion) $postFields["UseSpecificVersion"] = $useSpecificVersion;
        if ($version) $postFields["Version"] = $version;
        if ($xboxToken) $postFields["XboxToken"] = $xboxToken;

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/GetFriendLeaderboard",
            postFields: $postFields);
    }

    /**
     * Retrieves a list of ranked friends of the current player for the given statistic, centered on the requested
     * PlayFab user. If PlayFabId is empty or null will return currently logged in user.
     *
     * @param string $statisticName Statistic used to rank players for this leaderboard.
     * @param array $customTags The optional custom tags associated with the request (e.g. build number, external trace
     * identifiers, etc.).
     * @param array $externalPlatformFriends Indicates which other platforms' friends should be included in the
     * response. In HTTP, it is represented as a comma-separated list of platforms.
     * @param int $maxResultsCount Maximum number of entries to retrieve. Default 10, maximum 100.
     * @param string|null $playFabId PlayFab unique identifier of the user to center the leaderboard around. If null
     * will center on the logged in user.
     * @param array $profileConstraints If non-null, this determines which properties of the resulting player profiles
     * to return. For API calls from the client, only the allowed client profile properties for the title may be
     * requested. These allowed properties are configured in the Game Manager "Client Profile Options" tab in the
     * "Settings" section.
     * @param bool $useSpecificVersion If set to false, Version is considered null. If true, uses the specified Version
     * @param int|null $version The version of the leaderboard to get.
     * @param string $xboxToken Xbox token if Xbox friends should be included. Requires Xbox be configured on PlayFab.
     * @return array
     */
    public function get_friend_leaderboard_around_player(string $statisticName, array $customTags = [],
                                                         array  $externalPlatformFriends = [], int $maxResultsCount = 10,
                                                         string $playFabId = null, array $profileConstraints = [],
                                                         bool   $useSpecificVersion = false, int $version = null,
                                                         string $xboxToken = ""): array
    {
        $postFields = [
            "StatisticName" => $statisticName,
            "MaxResultsCount" => max(10, min($maxResultsCount, 100))
        ];
        if ($customTags) $postFields["CustomTags"] = $customTags;
        if ($externalPlatformFriends) $postFields["ExternalPlatformFriends"] = $externalPlatformFriends;
        if ($playFabId) $postFields["PlayFabId"] = $playFabId;
        if ($profileConstraints) $postFields["ProfileConstraints"] = $profileConstraints;
        if ($useSpecificVersion) $postFields["UseSpecificVersion"] = $useSpecificVersion;
        if ($version) $postFields["Version"] = $version;
        if ($xboxToken) $postFields["XboxToken"] = $xboxToken;

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/GetFriendLeaderboardAroundPlayer",
            postFields: $postFields);
    }

    /**
     * Retrieves a list of ranked users for the given statistic, starting from the indicated point in the leaderboard
     *
     * @param int $startPosition Position in the leaderboard to start this listing (defaults to the first entry).
     * @param string $statisticName Statistic used to rank players for this leaderboard.
     * @param array $customTags The optional custom tags associated with the request (e.g. build number, external trace
     * identifiers, etc.).
     * @param int $maxResultsCount Maximum number of entries to retrieve. Default 10, maximum 100.
     * @param array $profileConstraints If non-null, this determines which properties of the resulting player profiles
     * to return. For API calls from the client, only the allowed client profile properties for the title may be
     * requested. These allowed properties are configured in the Game Manager "Client Profile Options" tab in the
     * "Settings" section.
     * @param bool $useSpecificVersion If set to false, Version is considered null. If true, uses the specified Version
     * @param int $version The version of the leaderboard to get.
     * @return array
     */
    public function get_leaderboard(int  $startPosition, string $statisticName, array $customTags = [],
                                    int  $maxResultsCount = 10, array $profileConstraints = [],
                                    bool $useSpecificVersion = false, int $version = 0): array
    {
        $postFields = [
            "StartPosition" => max(0, $startPosition),
            "StatisticName" => $statisticName,
            "MaxResultsCount" => max(10, min($maxResultsCount, 100))
        ];
        if ($customTags) $postFields["IustomTags"] = $customTags;
        if ($profileConstraints) $postFields["ProfileConstraints"] = $profileConstraints;
        if ($useSpecificVersion) $postFields["UseSpecificVersion"] = $useSpecificVersion;
        if ($version) $postFields["Version"] = $version;

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/GetLeaderboard",
            postFields: $postFields);
    }

    /**
     * Retrieves a list of ranked users for the given statistic, centered on the requested player. If PlayFabId is empty
     * or null will return currently logged in user.
     *
     * @param string $statisticName Statistic used to rank players for this leaderboard.
     * @param array $customTags The optional custom tags associated with the request (e.g. build number, external trace
     * identifiers, etc.).
     * @param int $maxResutlsCount Maximum number of entries to retrieve. Default 10, maximum 100.
     * @param string $playFabId PlayFab unique identifier of the user to center the leaderboard around. If null will
     * center on the logged in user.
     * @param array $profileConstraints If non-null, this determines which properties of the resulting player profiles
     * to return. For API calls from the client, only the allowed client profile properties for the title may be
     * requested. These allowed properties are configured in the Game Manager "Client Profile Options" tab in the
     * "Settings" section.
     * @param bool $useSpecificVersion If set to false, Version is considered null. If true, uses the specified Version
     * @param int|null $version The version of the leaderboard to get.
     * @return array
     */
    public function get_leaderboard_around_player(string $statisticName, array $customTags = [],
                                                  int    $maxResutlsCount = 10, string $playFabId = "",
                                                  array  $profileConstraints = [], bool $useSpecificVersion = false,
                                                  int    $version = null): array
    {
        $postFields = [
            "StatisticName" => $statisticName,
            "MaxResultsCount" => max(10, min($maxResutlsCount, 100))
        ];
        if ($customTags) $postFields["CustomTags"] = $customTags;
        if ($playFabId) $postFields["PlayFabId"] = $playFabId;
        if ($profileConstraints) $postFields["ProfileConstraints"] = $profileConstraints;
        if ($useSpecificVersion) $postFields["UseSpecificVersion"] = $useSpecificVersion;
        if ($version) $postFields["Version"] = $version;

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/GetLeaderboardAroundPlayer",
            postFields: $postFields);
    }

    /**
     * Retrieves the information on the available versions of the specified statistic.
     *
     * @param string $statisticName unique name of the statistic
     * @param array $customTags The optional custom tags associated with the request (e.g. build number, external trace
     * identifiers, etc.).
     * @return array
     */
    public function get_player_statistic_versions(string $statisticName, array $customTags = []): array
    {
        $postFields = ["StatisticName" => $statisticName];
        if ($customTags) $postFields["CustomTags"] = $customTags;

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/GetPlayerStatisticVersions",
            postFields: $postFields);
    }

    /**
     * Retrieves the indicated statistics (current version and values for all statistics, if none are specified), for
     * the local player.
     *
     * @param array $customTags The optional custom tags associated with the request (e.g. build number, external trace
     * identifiers, etc.).
     * @param array $statisticNameVersions statistics to return, if StatisticNames is not set (only statistics which
     * have a version matching that provided will be returned)
     * @param array $statisticNames statistics to return (current version will be returned for each)
     * @return array
     */
    public function get_player_statistics(array $customTags = [], array $statisticNameVersions = [], array $statisticNames = []): array
    {
        $postFields = [];
        if ($customTags) $postFields["CustomTags"] = $customTags;
        if ($statisticNameVersions) $postFields["StatisticNameVersions"] = $statisticNameVersions;
        if ($statisticNames) $postFields["StatisticNames"] = $statisticNames;

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/GetPlayerStatistics",
            postFields: $postFields);
    }

    /**
     * Retrieves the indicated statistics (current version and values for all statistics, if none are specified), for
     * the local player.
     *
     * @param int $ifChangedFromDataVersion The optional custom tags associated with the request (e.g. build number,
     * external trace identifiers, etc.).
     * @param array $keys statistics to return, if StatisticNames is not set (only statistics which have a version
     * matching that provided will be returned)
     * @param string|null $playFabId statistics to return (current version will be returned for each)
     * @return array
     */
    public function get_user_data(int $ifChangedFromDataVersion = 0, array $keys = [], string $playFabId = null): array
    {
        $postFields = [];
        if ($ifChangedFromDataVersion) $postFields["IfChangedFromDataVersion"] = $ifChangedFromDataVersion;
        if ($keys) $postFields["Keys"] = $keys;
        if ($playFabId) $postFields["PlayFabId"] = $playFabId;

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/GetUserData",
            postFields: $postFields);
    }

    /**
     * Retrieves the publisher-specific custom data for the user which is readable and writable by the client
     *
     * @param int $ifChangedFromDataVersion
     * @param array $keys
     * @param string|null $playFabId
     * @return array
     */
    public function get_user_publisher_data(int $ifChangedFromDataVersion = 0, array $keys = [], string $playFabId = null): array
    {
        $postFields = [];
        if ($ifChangedFromDataVersion) $postFields["IfChangedFromDataVersion"] = $ifChangedFromDataVersion;
        if ($keys) $postFields["Keys"] = $keys;
        if ($playFabId) $postFields["PlayFabId"] = $playFabId;

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/GetUserPublisherData",
            postFields: $postFields);
    }

    /**
     * Retrieves the publisher-specific custom data for the user which can only be read by the client
     *
     * @param int $ifChangedFromDataVersion The version that currently exists according to the caller. The call will
     * return the data for all of the keys if the version in the system is greater than this.
     * @param array $keys List of unique keys to load from.
     * @param string|null $playFabId Unique PlayFab identifier of the user to load data for. Optional, defaults to
     * yourself if not set. When specified to a PlayFab id of another player, then this will only return public keys
     * for that account.
     * @return array
     */
    public function get_user_publisher_read_only_data(int    $ifChangedFromDataVersion = 0, array $keys = [],
                                                      string $playFabId = null): array
    {
        $postFields = [];
        if ($ifChangedFromDataVersion) $postFields["IfChangedFromDataVersion"] = $ifChangedFromDataVersion;
        if ($keys) $postFields["Keys"] = $keys;
        if ($playFabId) $postFields["PlayFabId"] = $playFabId;

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/GetUserPublisherReadOnlyData",
            postFields: $postFields);
    }

    /**
     * Retrieves the title-specific custom data for the user which can only be read by the client
     *
     * @param int $ifChangedFromDataVersion The version that currently exists according to the caller. The call will
     * return the data for all of the keys if the version in the system is greater than this.
     * @param array $keys List of unique keys to load from.
     * @param string|null $playFabId Unique PlayFab identifier of the user to load data for. Optional, defaults to
     * yourself if not set. When specified to a PlayFab id of another player, then this will only return public keys
     * for that account.
     * @return array
     */
    public function get_user_read_only_data(int    $ifChangedFromDataVersion = 0, array $keys = [],
                                            string $playFabId = null): array
    {
        $postFields = [];
        if ($ifChangedFromDataVersion) $postFields["IfChangedFromDataVersion"] = $ifChangedFromDataVersion;
        if ($keys) $postFields["Keys"] = $keys;
        if ($playFabId) $postFields["PlayFabId"] = $playFabId;

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/GetUserReadOnlyData",
            postFields: $postFields);
    }

    /**
     * Updates the values of the specified title-specific statistics for the user. By default, clients are not permitted
     * to update statistics. Developers may override this setting in the Game Manager > Settings > API Features.
     *
     * @param array $statistics Statistics to be updated with the provided values
     * @param array $customTags The optional custom tags associated with the request (e.g. build number, external trace
     * identifiers, etc.).
     * @return array
     */
    public function update_player_statistics(array $statistics, array $customTags = []): array
    {
        $postFields = ["Statistics" => $statistics];
        if ($customTags) $postFields["CustomTags"] = $customTags;

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/UpdatePlayerStatistics",
            postFields: $postFields);
    }

    /**
     * Creates and updates the title-specific custom data for the user which is readable and writable by the client
     *
     * @param array $data Key-value pairs to be written to the custom data. Note that keys are trimmed of whitespace,
     * are limited in size, and may not begin with a '!' character or be null.
     * @param array $customTags The optional custom tags associated with the request (e.g. build number, external trace
     * identifiers, etc.).
     * @param array $keysToRemove Optional list of Data-keys to remove from UserData. Some SDKs cannot insert null-
     * values into Data due to language constraints. Use this to delete the keys directly.
     * @param bool $isPublic Permission to be applied to all user data keys written in this request. Defaults to
     * "private" if not set. This is used for requests by one player for information about another player; those
     * requests will only return Public keys.
     * @return array
     */
    public function update_user_data(array $data, array $customTags = [], array $keysToRemove = [],
                                     bool  $isPublic = false): array
    {
        $postFields = ["Data" => $data];
        if ($customTags) $postFields["CustomTags"] = $customTags;
        if ($keysToRemove) $postFields["KeysToRemove"] = $keysToRemove;
        if ($isPublic) $postFields["Permission"] = "public";

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/UpdateUserData",
            postFields: $postFields);
    }

    /**
     * Creates and updates the publisher-specific custom data for the user which is readable and writable by the client
     *
     * @param array $data Key-value pairs to be written to the custom data. Note that keys are trimmed of whitespace,
     * are limited in size, and may not begin with a '!' character or be null.
     * @param array $customTags The optional custom tags associated with the request (e.g. build number, external trace
     * identifiers, etc.).
     * @param array $keysToRemove Optional list of Data-keys to remove from UserData. Some SDKs cannot insert null-
     * values into Data due to language constraints. Use this to delete the keys directly.
     * @param bool $isPublic Permission to be applied to all user data keys written in this request. Defaults to
     * "private" if not set. This is used for requests by one player for information about another player; those
     * requests will only return Public keys.
     * @return array
     */
    public function update_user_publisher_data(array $data, array $customTags = [], array $keysToRemove = [],
                                               bool  $isPublic = false): array
    {
        $postFields = ["Data" => $data];
        if ($customTags) $postFields["CustomTags"] = $customTags;
        if ($keysToRemove) $postFields["KeysToRemove"] = $keysToRemove;
        if ($isPublic) $postFields["Permission"] = "public";

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/UpdateUserPublisherData",
            postFields: $postFields);
    }


    /**
     * Player Item Management APIs
     *  ____  _                         ___ _                   __  __                                                   _
     * |  _ \| | __ _ _   _  ___ _ __  |_ _| |_ ___ _ __ ___   |  \/  | __ _ _ __   __ _  __ _  ___ _ __ ___   ___ _ __ | |_
     * | |_) | |/ _` | | | |/ _ \ '__|  | || __/ _ \ '_ ` _ \  | |\/| |/ _` | '_ \ / _` |/ _` |/ _ \ '_ ` _ \ / _ \ '_ \| __|
     * |  __/| | (_| | |_| |  __/ |     | || ||  __/ | | | | | | |  | | (_| | | | | (_| | (_| |  __/ | | | | |  __/ | | | |_
     * |_|   |_|\__,_|\__, |\___|_|    |___|\__\___|_| |_| |_| |_|  |_|\__,_|_| |_|\__,_|\__, |\___|_| |_| |_|\___|_| |_|\__|
     *                |___/                                                              |___/
     *
     * https://learn.microsoft.com/en-us/rest/api/playfab/client/player-item-management
     */

    public function add_user_virtual_currency(): array
    {
        $postFields = [];

        return $this->make_rest_call(endpoint: "endpoint",
            postFields: $postFields);
    }

    public function confirm_purchase(): array
    {
        $postFields = [];

        return $this->make_rest_call(endpoint: "endpoint",
            postFields: $postFields);
    }

    public function consume_item(): array
    {
        $postFields = [];

        return $this->make_rest_call(endpoint: "endpoint",
            postFields: $postFields);
    }

    public function get_character_inventory(): array
    {
        $postFields = [];

        return $this->make_rest_call(endpoint: "endpoint",
            postFields: $postFields);
    }

    public function get_payment_token(): array
    {
        $postFields = [];

        return $this->make_rest_call(endpoint: "endpoint",
            postFields: $postFields);
    }

    public function get_purchase(): array
    {
        $postFields = [];

        return $this->make_rest_call(endpoint: "endpoint",
            postFields: $postFields);
    }

    public function get_user_inventory(): array
    {
        $postFields = [];

        return $this->make_rest_call(endpoint: "endpoint",
            postFields: $postFields);
    }

    public function pay_for_purchase(): array
    {
        $postFields = [];

        return $this->make_rest_call(endpoint: "endpoint",
            postFields: $postFields);
    }

    public function purchase_item(): array
    {
        $postFields = [];

        return $this->make_rest_call(endpoint: "endpoint",
            postFields: $postFields);
    }

    public function redeem_coupon(): array
    {
        $postFields = [];

        return $this->make_rest_call(endpoint: "endpoint",
            postFields: $postFields);
    }

    public function start_purchase(): array
    {
        $postFields = [];

        return $this->make_rest_call(endpoint: "endpoint",
            postFields: $postFields);
    }

    public function subtract_user_virtual_currency(): array
    {
        $postFields = [];

        return $this->make_rest_call(endpoint: "endpoint",
            postFields: $postFields);
    }

    public function unlock_container_instance(): array
    {
        $postFields = [];

        return $this->make_rest_call(endpoint: "endpoint",
            postFields: $postFields);
    }

    public function unlock_container_item(): array
    {
        $postFields = [];

        return $this->make_rest_call(endpoint: "endpoint",
            postFields: $postFields);
    }


    /**
     * Shared Group Data APIs
     *  ____  _                        _    ____                         ____        _
     * / ___|| |__   __ _ _ __ ___  __| |  / ___|_ __ ___  _   _ _ __   |  _ \  __ _| |_ __ _
     * \___ \| '_ \ / _` | '__/ _ \/ _` | | |  _| '__/ _ \| | | | '_ \  | | | |/ _` | __/ _` |
     *  ___) | | | | (_| | | |  __/ (_| | | |_| | | | (_) | |_| | |_) | | |_| | (_| | || (_| |
     * |____/|_| |_|\__,_|_|  \___|\__,_|  \____|_|  \___/ \__,_| .__/  |____/ \__,_|\__\__,_|
     *                                                          |_|
     *
     * Shared Groups are designed for sharing data between a very small number of players, please see our guide:
     * https://docs.microsoft.com/gaming/playfab/features/social/groups/using-shared-group-data
     *
     * https://learn.microsoft.com/en-us/rest/api/playfab/client/shared-group-data
     */

    /**
     * Adds users to the set of those able to update both the shared data, as well as the set of users in the group.
     * Only users in the group can add new members.
     *
     * @param array $playFabIds An array of unique PlayFab assigned ID of the user on whom the operation will be performed.
     * @param string $sharedGroupId Unique identifier for the shared group.
     * @return array
     */
    public function add_shared_group_members(array $playFabIds, string $sharedGroupId): array
    {
        $postFields = [
            "PlayFabIds" => $playFabIds,
            "SharedGroupId" => $sharedGroupId
        ];

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/AddSharedGroupMembers",
            postFields: $postFields);
    }

    /**
     * Requests the creation of a shared group object, containing key/value pairs which may be updated by all members of
     * the group. Upon creation, the current user will be the only member of the group.
     *
     * @param string|null $sharedGroupId Unique identifier for the shared group (a random identifier will be assigned,
     * if one is not specified).
     * @return array
     */
    public function create_shared_group(string $sharedGroupId = null): array
    {
        $postFields = [];
        if ($sharedGroupId) $postFields["SharedGroupId"] = $sharedGroupId;

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/CreateSharedGroup",
            postFields: $postFields);
    }

    /**
     * Retrieves data stored in a shared group object, as well as the list of members in the group. Non-members of the
     * group may use this to retrieve group data, including membership, but they will not receive data for keys marked
     * as private.
     *
     * @param string $sharedGroupId Unique identifier for the shared group.
     * @param bool $getMembers If true, return the list of all members of the shared group.
     * @param array|null $keys Specific keys to retrieve from the shared group (if not specified, all keys will be
     * returned, while an empty array indicates that no keys should be returned).
     * @return array
     */
    public function get_shared_group_data(string $sharedGroupId, bool $getMembers = true, array $keys = null): array
    {
        $postFields = [
            "SharedGroupId" => $sharedGroupId,
            "GetMembers" => $getMembers
        ];
        if ($keys) $postFields["Keys"] = $keys;

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/GetSharedGroupData",
            postFields: $postFields);
    }

    /**
     * Removes users from the set of those able to update the shared data and the set of users in the group. Only users
     * in the group can remove members. If as a result of the call, zero users remain with access, the group and its
     * associated data will be deleted.
     *
     * @param array $playFabIds An array of unique PlayFab assigned ID of the user on whom the operation will be
     * performed.
     * @param string $sharedGroupId Unique identifier for the shared group.
     * @return array
     */
    public function remove_shared_group_members(array $playFabIds, string $sharedGroupId): array
    {
        $postFields = [
            "PlayFabIds" => $playFabIds,
            "SharedGroupId" => $sharedGroupId
        ];

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/RemoveSharedGroupMembers",
            postFields: $postFields);
    }

    /**
     * Adds, updates, and removes data keys for a shared group object. If the permission is set to Public, all fields
     * updated or added in this call will be readable by users not in the group. By default, data permissions are set to
     * Private. Regardless of the permission setting, only members of the group can update the data.
     *
     * @param string $sharedGroupId Unique identifier for the shared group.
     * @param array|null $customTags The optional custom tags associated with the request (e.g. build number, external
     * trace identifiers, etc.).
     * @param array|null $data Key-value pairs to be written to the custom data. Note that keys are trimmed of
     * whitespace, are limited in size, and may not begin with a '!' character or be null.
     * @param array|null $keysToRemove Optional list of Data-keys to remove from UserData. Some SDKs cannot insert null-
     * values into Data due to language constraints. Use this to delete the keys directly.
     * @param bool $isPublic Permission to be applied to all user data keys in this request.
     * @return array
     */
    public function update_shared_group_data(string $sharedGroupId, array $customTags = null, array $data = null,
                                             array  $keysToRemove = null, bool $isPublic = false): array
    {
        $permission = new UserDataPermission(isPublic: $isPublic);
        $postFields = ["SharedGroupId" => $sharedGroupId];
        if ($customTags) $postFields["CustomTags"] = $customTags;
        if ($data) $postFields["Data"] = $data;
        if ($keysToRemove) $postFields["Keys"] = $keysToRemove;
        if ($isPublic) $postFields["Permission"] = $permission();

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/UpdateSharedGroupData",
            postFields: $postFields);
    }


    /**
     * Title-Wide Data Management APIs
     *  _____ _ _   _         __        ___     _        ____        _          __  __                                                   _
     * |_   _(_) |_| | ___    \ \      / (_) __| | ___  |  _ \  __ _| |_ __ _  |  \/  | __ _ _ __   __ _  __ _  ___ _ __ ___   ___ _ __ | |_
     *   | | | | __| |/ _ \____\ \ /\ / /| |/ _` |/ _ \ | | | |/ _` | __/ _` | | |\/| |/ _` | '_ \ / _` |/ _` |/ _ \ '_ ` _ \ / _ \ '_ \| __|
     *   | | | | |_| |  __/_____\ V  V / | | (_| |  __/ | |_| | (_| | || (_| | | |  | | (_| | | | | (_| | (_| |  __/ | | | | |  __/ | | | |_
     *   |_| |_|\__|_|\___|      \_/\_/  |_|\__,_|\___| |____/ \__,_|\__\__,_| |_|  |_|\__,_|_| |_|\__,_|\__, |\___|_| |_| |_|\___|_| |_|\__|
     *                                                                                                   |___/
     *
     * https://learn.microsoft.com/en-us/rest/api/playfab/client/title-wide-data-management
     */

    /**
     * NOTE: This is a Legacy Economy API, and is in bugfix-only mode. All new Economy features are being developed only
     * for /Catalog/GetItems version 2. Retrieves the specified version of the title's catalog of virtual goods,
     * including all defined properties
     *
     * @param string|null $catalogVersion Which catalog is being requested. If null, uses the default catalog.
     * @return array
     */
    public function get_catalog_items(string $catalogVersion = null): stdClass
    {
        $hours = $this->hours_since_last_api_call_to_endpoint("/Client/GetCatalogItems");

        if ($this->macawStaleHoursCatalog < $hours or true) {
            $postFields = [];
            if ($catalogVersion !== null) $postFields["CatalogVersion"] = $catalogVersion;
            $response = $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/GetCatalogItems",
                postFields: $postFields);

//            $data = $this->parse_response_object($response->Catalog);
            $items = $response->Catalog;
            $catalogKeys = [];
            foreach ($items as $item) {
                $catalogKeys += array_keys(get_object_vars($item));
                die;
            }
            $catalogKeys = array_unique($catalogKeys);
        }
    }

    /**
     * Retrieves the key-value store of custom publisher settings
     *
     * @param array|null $keys array of keys to get back data from the Publisher data blob, set by the admin tools
     * @return array
     */
    public function get_publisher_data(array $keys = null): array
    {
        $postFields = [];
        if ($keys !== null) $postFields["Keys"] = $keys;

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/GetPublisherData",
            postFields: $postFields);
    }

    /**
     * NOTE: This is a Legacy Economy API, and is in bugfix-only mode. All new Economy features are being developed only
     * for version 2. Retrieves the set of items defined for the specified store, including all prices defined
     *
     * @param string $storeId Unqiue identifier for the store which is being requested.
     * @param string|null $catalogVersion Catalog version to store items from. Use default catalog version if null
     * @return array
     */
    public function get_store_items(string $storeId, string $catalogVersion = null): array
    {
        $postFields = [
            "StoreId" => $storeId,
            "CatalogVersion" => $catalogVersion
        ];

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/GetStoreItems",
            postFields: $postFields);
    }

    /**
     * Retrieves the current server time
     *
     * @return array
     */
    public function get_time(): array
    {
        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/GetTime");
    }

    /**
     * Retrieves the key-value store of custom title settings
     *
     * @param array|null $keys Specific keys to search for in the title data (leave null to get all keys)
     * @param string|null $overrideLabel Optional field that specifies the name of an override. This value is ignored
     * when used by the game client; otherwise, the overrides are applied automatically to the title data.
     * @return array
     */
    public function get_title_data(array $keys = null, string $overrideLabel = null): array
    {
        $endpoint = "/Client/GetTitleData";

        $hours = $this->hours_since_last_api_call_to_endpoint(endpoint: $endpoint, statusCode: "200");

        if ($this->macawStaleHoursTitleData < $hours and $this->macawAutoRefresh) {
            $postFields = [
                "Keys" => $keys,
                "OverrideLabel" => $overrideLabel
            ];

            $response = $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com{$endpoint}",
                postFields: $postFields);

            $this->ingest_data($response->Data, tablePrefix: "macaw_title_data_");
        }

        return [];
    }

    /**
     * Retrieves the title news feed, as configured in the developer portal
     *
     * @param int $count Limits the results to the last n entries. Defaults to 10 if not set.
     * @return array
     */
    public function get_title_news(int $count = 10): array
    {
        $endpoint = "/Client/GetTitleNews";
        $postFields = ["Count" => $count];

        $hours = $this->hours_since_last_api_call_to_endpoint(endpoint: $endpoint, statusCode: "200");

        if ($this->macawStaleHoursNews < $hours and $this->macawAutoRefresh) {
            $data = $this->make_rest_call(
                endpoint: "https://$this->titleId.playfabapi.com/Client/GetTitleNews",
                postFields: $postFields
            );

            $rowData = [];
            foreach ($data->News as $news) {
                $rowData[] = [
                    "news_id" => $news->NewsId,
                    "timestamp" => trim(preg_replace("/[TZ]/i", " ", $news->Timestamp)),
                    "title" => $news->Title,
                    "body" => $news->Body
                ];
            }

            if ($rowData) {
                $this->insert("macaw_title_news", $rowData);
            }
        }

        return $this->select("macaw_title_news", orderBy: ["timestamp" => "DESC"], limit: 0);
    }


    /**
     * Trading Management APIs
     *  _____              _ _
     * |_   _| __ __ _  __| (_)_ __   __ _
     *   | || '__/ _` |/ _` | | '_ \ / _` |
     *   | || | | (_| | (_| | | | | | (_| |
     *   |_||_|  \__,_|\__,_|_|_| |_|\__, |
     *                               |___/
     *
     * https://learn.microsoft.com/en-us/rest/api/playfab/client/trading
     */

    /**
     * Accepts an open trade (one that has not yet been accepted or cancelled), if the locally signed-in player is in
     * the allowed player list for the trade, or it is open to all players. If the call is successful, the offered and
     * accepted items will be swapped between the two players' inventories.
     *
     * @param string $offeringPlayerId Player who opened the trade.
     * @param string $tradeId Player who opened the trade.
     * @param array|null $acceptedInventoryInstanceIds Items from the accepting player's inventory in exchange for the
     * offered items in the trade. In the case of a gift, this will be null.
     * @return array
     */
    public function accept_trade(string $offeringPlayerId, string $tradeId, array $acceptedInventoryInstanceIds = null): array
    {
        $postFields = [
            "OfferingPlayerId" => $offeringPlayerId,
            "TradeId" => $tradeId,
        ];
        if (!empty($acceptedInventoryInstanceIds)) $postFields["AcceptedInventoryInstanceIds"] = $acceptedInventoryInstanceIds;

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/AcceptTrade",
            postFields: $postFields);
    }

    /**
     * Cancels an open trade (one that has not yet been accepted or cancelled). Note that only the player who created
     * the trade can cancel it via this API call, to prevent griefing of the trade system (cancelling trades in order
     * to prevent other players from accepting them, for trades that can be claimed by more than one player).
     *
     * @param string $tradeId Trade identifier.
     * @return array
     */
    public function cancel_trade(string $tradeId): array
    {
        $postFields = ["TradeId" => $tradeId];

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/CancelTrade",
            postFields: $postFields);
    }

    /**
     * Gets all trades the player has either opened or accepted, optionally filtered by trade status.
     *
     * @param string|null $statusFilter Returns only trades with the given status. If null, returns all trades.
     * @return array
     */
    public function get_player_trades(string $statusFilter = null): array
    {
        $postFields = [];
        if (!empty($statusFilter)) $postFields["StatusFilter"] = $this->validate_trade_status($statusFilter);

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/GetPlayerTrades",
            postFields: $postFields);
    }

    /**
     * Gets the current status of an existing trade.
     *
     * @param string $offeringPlayerId Player who opened trade.
     * @param string $tradeId Trade identifier as returned by OpenTradeOffer.
     * @return array
     */
    public function get_trade_status(string $offeringPlayerId, string $tradeId): array
    {
        $postFields = [
            "OfferingPlayerId" => $offeringPlayerId,
            "TradeId" => $tradeId,
        ];

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/GetTradeStatus",
            postFields: $postFields);
    }

    /**
     * Opens a new outstanding trade. Note that a given item instance may only be in one open trade at a time.
     *
     * @param array $allowedPlayerIds Players who are allowed to accept the trade. If null, the trade may be accepted by
     * any player. If empty, the trade may not be accepted by any player.
     * @param array $offeredInventoryInstanceIds Player inventory items offered for trade. If not set, the trade is
     * effectively a gift request.
     * @param array $requestedCatalogItemIds Catalog items accepted for the trade. If not set, the trade is effectively
     * a gift.
     * @return array
     */
    public function open_trade(array $allowedPlayerIds, array $offeredInventoryInstanceIds,
                               array $requestedCatalogItemIds = []): array
    {
        $postFields = [
            "AllowedPlayerIds" => $allowedPlayerIds,
            "OfferedInventoryInstanceIds" => $offeredInventoryInstanceIds
        ];
        if ($requestedCatalogItemIds) $postFields["RequestedCatalogItemIds"] = $requestedCatalogItemIds;

        return $this->make_rest_call(endpoint: "https://$this->titleId.playfabapi.com/Client/OpenTrade",
            postFields: $postFields);
    }


    /**
     * Input Validation
     *  ___                   _    __     __    _ _     _       _   _
     * |_ _|_ __  _ __  _   _| |_  \ \   / /_ _| (_) __| | __ _| |_(_) ___  _ __
     *  | || '_ \| '_ \| | | | __|  \ \ / / _` | | |/ _` |/ _` | __| |/ _ \| '_ \
     *  | || | | | |_) | |_| | |_    \ V / (_| | | | (_| | (_| | |_| | (_) | | | |
     * |___|_| |_| .__/ \__,_|\__|    \_/ \__,_|_|_|\__,_|\__,_|\__|_|\___/|_| |_|
     *           |_|
     */

    /**
     * Returns valid trade status or `null` if invalid
     *
     * @param string $tradeStatus
     * @return string|null
     */
    protected function validate_trade_status(string $tradeStatus): string|null
    {
        $validValues = ["Accepted", "Accepting", "Cancelled", "Filled", "Invalid", "Open", "Opening"];

        if (in_array(ucfirst(strtolower($tradeStatus)), $validValues)) return $tradeStatus;

        return null;
    }
}
