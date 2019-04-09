<?php

/**
 * Created by PhpStorm.
 * User: dirk
 * Date: 19-2-17
 * Time: 14:31
 */
class OvChip
{
    // Used to authenticate and authorise
    private $CLIENT_ID = "nmOIiEJO5khvtLBK9xad3UkkS8Ua";
    private $CLIENT_SECRET = "FE8ef6bVBiyN0NeyUJ5VOWdelvQa";
    private $gebruikersnaam = "";
    private $wachtwoord = "";

    // Global private variables
    private $accessToken;
    private $idToken;
    private $refreshToken;
    private $tokenExpire;
    private $authorizationToken;
    private $firstTransaction = [];

    /**
     * OvChip constructor.
     *
     * @param string $username
     * @param string $password
     *
     * @throws \Error
     */
    function __construct(string $username, string $password)
    {
        if (isset($username) && isset($password)) {
            $this->gebruikersnaam = $username;
            $this->wachtwoord = $password;
        } else {
            throw new Error("Arguments are not valid");
        }
        $this->getTokens();
    }


    /**
     * Get the tokens needed for authorization.
     */
    private function getTokens()
    {

        // Post data
        $data = [
            'grant_type' => 'password',
            'username' => $this->gebruikersnaam,
            'password' => $this->wachtwoord,
            'client_id' => $this->CLIENT_ID,
            'client_secret' => $this->CLIENT_SECRET,
            'scope' => 'openid',
        ];
        $url = "https://login.ov-chipkaart.nl/oauth2/token";

        $tokenResponse = (self::Execute($url, $data));

        $this->tokenExpire = (new DateTime())->add((new DateInterval('PT' . $tokenResponse['expires_in'] . 'S')));
        $this->refreshToken = $tokenResponse['refresh_token'];
        $this->idToken = $tokenResponse['id_token'];
        $this->accessToken = $tokenResponse['access_token'];

        $this->getAuthorization();
    }

    /**
     * Refresh the tokens
     *
     */
    private function refreshToken()
    {
        // Post data
        $data = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->refreshToken,
            'client_id' => $this->CLIENT_ID,
            'client_secret' => $this->CLIENT_SECRET,
        ];
        $url = "https://login.ov-chipkaart.nl/oauth2/token";
        $refreshResponse = (self::Execute($url, $data));

        $this->tokenExpire = (new DateTime())->add((new DateInterval('PT' . $refreshResponse['expires_in'] . 'S')));
        $this->refreshToken = $refreshResponse['refresh_token'];
        $this->idToken = $refreshResponse['id_token'];
        $this->accessToken = $refreshResponse['access_token'];
    }


    /**
     * Get the authorization token to get access to cards and transactions
     *
     */
    private function getAuthorization()
    {
        // Post id_token
        if (new DateTime() > $this->tokenExpire) {
            $this->refreshToken();
        }
        $data = [
            'authenticationToken' => $this->idToken,
        ];
        $url = "https://api2.ov-chipkaart.nl/femobilegateway/v1/api/authorize";
        $authorizationResponse = (self::Execute($url, $data));

        // Returns string
        $this->authorizationToken = $authorizationResponse['o'];
    }

    /**
     * If current time is bigger than the expire time, refresh the tokens
     */
    private function checkTokenExpire()
    {
        if (new DateTime() > $this->tokenExpire) {
            $this->refreshToken();
            $this->getAuthorization();
        }
    }

    /**
     * Get the cards for current 'user'
     *
     * @return array With cards
     * @throws \Error
     */
    function getCardList()
    {
        $this->checkTokenExpire();

        // Post authorized token
        $data = [
            'authorizationToken' => $this->authorizationToken,
            'locale' => 'nl-NL',
        ];
        $url = "https://api2.ov-chipkaart.nl/femobilegateway/v1/cards/list";
        $cardsResponse = (self::Execute($url, $data));

        return $cardsResponse['o'];
    }

    /**
     * Get information for specific card.
     *
     * @param string $mediumId
     *
     * @return array
     * @throws \Error
     */
    function getCardInfo(string $mediumId)
    {
        $this->checkTokenExpire();

        $data = [
            'authorizationToken' => $this->authorizationToken,
            'locale' => 'nl-NL',
            'mediumId' => $mediumId,
        ];

        $url = "https://api2.ov-chipkaart.nl/femobilegateway/v1/card/";
        $cardInfoRespone = self::Execute($url, $data);
        return $cardInfoRespone['o'];

    }


    /**
     * Get all transactions and writes to csv file.
     *
     * @param string $mediumId
     * @param bool $toCSV When true it will write all records to csv file, else returns all records
     *
     * @return array|bool
     * @throws \Error
     */
    function getTransactions(string $mediumId, bool $toCSV = false)
    {
        // Refreshing token to be sure ;)
        $this->refreshToken();
        $this->getAuthorization();

        $records = [];
        // Transaction offset. PreviousOffset -1 to start the while loop
        $offset = 0;
        $transactions = [
            'o' => [
                'totalSize' => 21,
            ],
        ];

        if ($toCSV) {
            // CSV headers
            $csvHeader =
                [
                    'checkInInfo',
                    'checkInText',
                    'fare',
                    'fareCalculation',
                    'fareText',
                    'modalType',
                    'productInfo',
                    'productText',
                    'pto',
                    'transactionDateTime',
                    'transactionInfo',
                    'transactionName',
                    'ePurseMut',
                    'ePurseMutInfo',
                    'transactionExplanation',
                    'transactionPriority'
                ];
            $fp = fopen('output.csv', 'w');
            fputcsv($fp, $csvHeader, ';');
        }

        // Url
        $url = "https://api2.ov-chipkaart.nl/femobilegateway/v1/transactions";

        // Get all transactions
        while ($offset < $transactions['o']['totalSize']) {
            // Post data
            $today = date('Y-m-d');
            $data = [
                'authorizationToken' => $this->authorizationToken,
                'mediumId' => $mediumId,
                'offset' => 0,
                'startDate' => '1970-01-01',
                'endDate' => $today,
                'locale' => 'nl-NL',
            ];

            $transactions = (self::Execute($url, $data));

            // Offset for next request
            $offset = $offset + 20;

            // Write transaction records to csv
            if ($toCSV) {
                foreach ($transactions['o']['records'] as $row) {
                    fputcsv($fp, $row, ';');
                }
            } else {
                foreach ($transactions['o']['records'] as $row) {
                    array_push($records, $row);
                }
            }
            print_r($offset . " / " . $transactions['o']['totalSize'] . "\n");
        }
        // close file
        if ($toCSV) {
            fclose($fp);
            return true;
        } else {
            return $records;
        }
    }


    /**
     * Get the in-app faq
     *
     * @return mixed
     * @throws \Error
     */
    function getFAQ()
    {
        $this->checkTokenExpire();

        $data = [
            'authorizationToken' => $this->authorizationToken,
            'locale' => 'nl-NL',
        ];
        $url = "https://api2.ov-chipkaart.nl/femobilegateway/v1/faq/list";
        $faqResponse = self::Execute($url, $data);
        return $faqResponse['o']['records'];
    }

    /**
     * Get the first transaction made with the specifeid medium
     *
     * @param string $mediumId
     *
     * @return mixed
     * @throws \Error
     */
    function getFirstTransaction(string $mediumId)
    {
        $today = date('Y-m-d');
        $data = [
            'authorizationToken' => $this->authorizationToken,
            'mediumId' => $mediumId,
            'offset' => 0,
            'startDate' => '1970-01-01',
            'endDate' => $today,
            'locale' => 'nl-NL',
        ];

        $url = "https://api2.ov-chipkaart.nl/femobilegateway/v1/transactions";

        $transactions = self::Execute($url, $data);
        $data['offset'] = ($transactions['o']['totalSize'] - 1);

        return (self::Execute($url, $data))['o']['records'][0];
    }

    /**
     * Execute is used to perform a curl request.
     *
     * @param string $Address / url
     * @param array $Data Array with data to post to $Address
     *
     * @return mixed
     * @throws \Error
     */
    static function Execute(string $Address, array $Data)
    {
        $Connection = curl_init();

        /* Split array to x-www-form-urlencoded */
        $Fields = '';
        foreach ($Data as $Key => $Value) {
            $Fields .= '&' . $Key . '=' . urlencode($Value);
        }
        if (!empty($Fields)) {
            $Fields = substr($Fields, 1);
        }
        curl_setopt($Connection, CURLOPT_URL, $Address);

        // Set headers
        $Header = array('Content-Type: application/x-www-form-urlencoded');

        curl_setopt($Connection, CURLOPT_HTTPHEADER, $Header);
        // Post data
        curl_setopt($Connection, CURLOPT_POST, true);
        curl_setopt($Connection, CURLOPT_POSTFIELDS, ($Fields));
        curl_setopt($Connection, CURLOPT_RETURNTRANSFER, true);

        // Execute
        $Result = curl_exec($Connection);
        $Info = curl_getinfo($Connection, CURLINFO_HTTP_CODE);
//			echo "Got a " . $Info . " statuscode..\n";
        if ($Info != 200) {
            throw new Error($Result);
        }
        curl_close($Connection);

        // Return result
        return json_decode($Result, true);
    }
}