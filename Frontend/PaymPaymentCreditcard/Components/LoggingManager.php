<?php

require_once dirname(__FILE__) . '/../lib/Services/Paymill/LoggingInterface.php';
/**
 * Paymill Logging for shopware
 *
 * @category   Shopware
 * @package    Shopware_Plugins
 * @subpackage Paymill
 * @author     Paymill
 */
class Shopware_Plugins_Frontend_PaymPaymentCreditcard_Components_LoggingManager implements Services_Paymill_LoggingInterface
{
    /*** @var string */
    private $_processId = '';

    /**
     * Creates an instance of the LoggingManager
     *
     * @param string $processId
     */
    function __construct($processId = '')
    {
        $this->_processId = $processId;
    }

    /**
     * Returns the process id used for connected search
     * @return string
     */
    public function getProcessId()
    {
        return $this->_processId;
    }

    /**
     * Sets the process id used for connected search
     * @param string $processId
     */
    public function setProcessId($processId)
    {
        $this->_processId = $processId;
    }

    /**
     * This method is meant to be called during the installation of the plugin to allow use of the LoggingManager.
     *
     * @throws Exception "There was an Error creating the Log-Table:"
     */
    public static function install()
    {

        try {
            $sql = "CREATE TABLE IF NOT EXISTS `paymill_log` (" .
                   "`id` int(11) NOT NULL AUTO_INCREMENT," .
                   "`processId` varchar(250) NOT NULL," .
                   "`entryDate` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP," .
                   "`version` varchar(25) NOT NULL COLLATE utf8_unicode_ci," .
                   "`merchantInfo` varchar(250) COLLATE utf8_unicode_ci NOT NULL," .
                   "`devInfo` text COLLATE utf8_unicode_ci DEFAULT NULL," .
                   "PRIMARY KEY (`id`)" .
                   ") ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=1;".
                   "DROP TABLE IF EXISTS `pigmbh_paymill_log`;";

            Shopware()->Db()->query($sql);
        } catch (Exception $exception) {
            throw new Exception("There was an Error creating the Log-Table: " . $exception->getMessage());
        }
    }

    /**
     * getTotal(void)<br/>
     * Returns the number of entries in the paymill_log table.<br/>
     *
     * @uses selectOne($query) protected method of this class, make sure to override it to allow access.
     * @return int Number of Entries
     */
    public function getTotal()
    {
        $getTotal = "SELECT count(*) FROM `paymill_log`";
        $count = Shopware()->Db()->fetchOne($getTotal);

        return $count;
    }

    /**
     * read(int,int)<br/>
     * Reads all entries from the paymill_log table, filters for start and limit numbers and returns them as an array.
     *
     * @param int    $start     first value to be read.(Recommended range: 0 to n)
     * @param int    $limit     Number of values to be read. (Recommended range: 1 to n)
     *
     * @param string $property  the property to sort by
     * @param string $direction either asc or desc for the sort direction
     * @param string $searchTerm
     * @param bool   $connectedSearch
     *
     * @return array result
     */
    public function read($start, $limit, $property, $direction, $searchTerm = "", $connectedSearch = false)
    {
        //Cast arguments to int to avoid insecure values
        $start = (int)$start;
        $limit = (int)$limit;
        $preparedArray = array();

        if($direction !== 'ASC' && $direction !== 'DESC') {
            $direction = 'DESC';
        }

        $possibleProperty = array('id', 'processId', 'entryDate', 'version', 'merchantInfo', 'devInfo');
        if(!in_array($property, $possibleProperty)) {
            $property = 'entryDate';
        }

        if($searchTerm !== ""){
            $searchTerm = '%' . $searchTerm . '%';
            $sqlWhere = 'WHERE (merchantInfo LIKE ? OR devInfo LIKE ?) ';
            array_push($preparedArray, $searchTerm);
            array_push($preparedArray, $searchTerm);
        }

        if($connectedSearch) {
            $sqlWhere = "WHERE processId IN (SELECT processId FROM `paymill_log` $sqlWhere)";
        }

        $sqlSelect = "SELECT * FROM  `paymill_log` ";

        $sqlOrder = "ORDER BY  `paymill_log`.". $property  ." ". $direction ." LIMIT ". $start .", " . $limit;

        $sql = $sqlSelect . $sqlWhere . $sqlOrder;

        //Process Select and return result
        $result = Shopware()->Db()->fetchAll($sql,$preparedArray);

        return $result;
    }

    /**
     * write(string, string, string [can be null])<br />
     * Uses the abstract method insertOne() to insert the arguments into the db log
     *
     * @param String $merchantInfo      Log Message for the Merchant to understand. <b>Keep this one easy.</b>
     * @param String $devInfo           Log information for the dev in here. If you like to log an array, this is the place.
     */
    public function log($merchantInfo, $devInfo)
    {
        $doLog = $this->getLoggingMode();
        if ($doLog) {
            $sql = "INSERT INTO `paymill_log`(`processId`, `version`, `merchantInfo`, `devInfo`) VALUES(?,?,?,?)";
            $version = Shopware()->Plugins()->Frontend()->PaymPaymentCreditcard()->getVersion();

            Shopware()->Db()->query($sql, array($this->_processId, $version, $merchantInfo, $devInfo));
        }
    }

    /**
     * Returns the state of the logging option from the backend as a boolean
     *
     * @return Boolean
     */
    public function getLoggingMode()
    {
        $swConfig = Shopware()->Plugins()->Frontend()->PaymPaymentCreditcard()->Config();

        return $swConfig->get('paymillLogging');
    }
}
