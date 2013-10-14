<?php

/**
 * Shopware 4.0
 * Copyright © 2012 shopware AG
 *
 * According to our dual licensing model, this program can be used either
 * under the terms of the GNU Affero General Public License, version 3,
 * or under a proprietary license.
 *
 * The texts of the GNU Affero General Public License with an additional
 * permission and of our proprietary license can be found at and
 * in the LICENSE file you have received along with this program.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * "Shopware" is a registered trademark of shopware AG.
 * The licensing of the program under the AGPLv3 does not imply a
 * trademark license. Therefore any rights, title and interest in
 * our trademarks remain entirely with us.
 *
 * @category   Shopware
 * @package    Shopware_Plugins
 * @subpackage Paymill
 * @author     Paymill
 */
class Shopware_Controllers_Frontend_PaymentPaymill extends Shopware_Controllers_Frontend_Payment
{
    /**
     * Frontend index action controller
     *
     * @return void
     */
    public function indexAction()
    {
        // read transaction token from session
        $paymillToken = Shopware()->Session()->paymillTransactionToken;

        // check if token present
        if (empty($paymillToken)) {
            $this->log("No paymill token was provided. Redirect to payments page.", null);

            $url = $this->Front()->Router()->assemble(array('action'      => 'payment', 'sTarget' => 'checkout',
                                                            'sViewport'   => 'account', 'appendSession' => true,
                                                            'forceSecure' => true));

            $this->redirect($url . '&paymill_error=1');
        }

        if ($paymillToken === "NoTokenRequired") {
            $this->log("Start processing payment without token.", $paymillToken);
        } else {
            $this->log("Start processing payment with token.", $paymillToken);
        }

        $user = Shopware()->Session()->sOrderVariables['sUserData'];

        // process the payment
        $swConfig = Shopware()->Plugins()->Frontend()->PaymPaymentCreditcard()->Config();
        $userId = $user['billingaddress']['userID'];
        $paymentShortcut = $this->getPaymentShortName() == 'paymillcc' ? 'cc' : 'elv';
        $params = array('token'            => $paymillToken,
                        'authorizedAmount' => (int)Shopware()->Session()->paymillTotalAmount,
                        'amount'           => (int)(round($this->getAmount() * 100, 0)),
                        'currency'         => $this->getCurrencyShortName(),
                        'name'             => $user['billingaddress']['lastname'] . ', ' . $user['billingaddress']['firstname'],
                        'email'            => $user['additional']['user']['email'],
                        'description'      => $user['additional']['user']['email'] . " " . Shopware()->Config()->get('shopname'),
                        'payment'          => $paymentShortcut);


        $paymentProcessor = new Shopware_Plugins_Frontend_PaymPaymentCreditcard_Components_PaymentProcessor($params);

        //Fast Checkout data exists
        $fcHelper = new Shopware_Plugins_Frontend_PaymPaymentCreditcard_Components_FastCheckoutHelper($userId, $paymentShortcut);

        if ($fcHelper->loadClientId()) {
            $clientId = $fcHelper->clientId;
            $paymentProcessor->setClientId($clientId);
        }

        if ($fcHelper->entryExists()) {
            if ($paymillToken === "NoTokenRequired") {
                $paymentId = $fcHelper->paymentId;
                $paymentProcessor->setPaymentId($paymentId);
                $this->log("Processing Payment with Parameters", print_r($params, true), "Additional Parameters given. \n" . " User Id: " . $userId . "\n Client Id: " . $clientId . "\n PaymentId: " . $paymentId);
            }
        } else {
            $this->log("Processing Payment with Parameters", print_r($params, true));
        }

        $preAuth = $swConfig->get("paymillPreAuth") == 1;
        $result = $paymentProcessor->processPayment(!$preAuth);

        $this->log("Payment processing resulted in: " . ($result ? "Success" : "Failure"), print_r($result, true));

        // finish the order if payment was successfully processed
        if ($result !== true) {
            Shopware()->Session()->paymillTransactionToken = null;
            Shopware()->Session()->pigmbhErrorMessage = "An error occured while processing your payment";
            if (Shopware()->Locale()->getLanguage() === 'de') {
                Shopware()->Session()->pigmbhErrorMessage = 'W&auml;hrend Ihrer Zahlung ist ein Fehler aufgetreten.';
            }

            return $this->forward('error');
        }


        //Save Client Id
        $clientId = $paymentProcessor->getClientId();
        $fcHelper->saveClientId($clientId);

        //Save Fast Checkout Data
        $isFastCheckoutEnabled = $swConfig->get("paymillFastCheckout");
        if ($isFastCheckoutEnabled) {
            $paymentId = $paymentProcessor->getPaymentId();
            $this->log("Saving FC Data for User: $userId with the payment: $paymentShortcut", $paymentId);
            $fcHelper->savePaymentId($paymentId);
        }

        //Create the order
        $finalPaymillToken = $paymillToken === "NoTokenRequired" ? $this->createPaymentUniqueId() : $paymillToken;
        $orderNumber = $this->saveOrder($finalPaymillToken, md5($finalPaymillToken));
        $this->log("Finish order.", "Ordernumber: " . $orderNumber, "using Token: " . $finalPaymillToken);

        if($preAuth){
            $manager = Shopware()->Models();
            $orderId = Shopware()->Db()->fetchOne("SELECT id FROM s_order WHERE ordernumber=?",array($orderNumber));
            $model = Shopware()->Models()->getRepository( 'Shopware\Models\Order\Order' )->findOneById( $orderId );
            $model->getAttribute()->setPaymillPreAuthorization($paymentProcessor->getPreauthId());
            $manager->persist($model);
            $manager->flush();
            $this->log("Saved PreAuth Information for $orderNumber",$paymentProcessor->getPreauthId());

        }

        //Update Transaction
        require_once dirname(__FILE__) . '/../../lib/Services/Paymill/Transactions.php';
        $privateKey = trim($swConfig->get("privateKey"));
        $apiUrl = "https://api.paymill.com/v2/";
        $transaction = new Services_Paymill_Transactions($privateKey, $apiUrl);
        $description = $orderNumber . " " . $user['additional']['user']['email'] . " " . Shopware()->Config()
                                                                                         ->get('shopname');
        $updateResponse = $transaction->update(array(id          => $paymentProcessor->getTransactionId(),
                                                     description => $description));

        if ($updateResponse['response_code'] === 20000) {
            $this->log("Successfully updated the description of " . $paymentProcessor->getTransactionId(), $description);
        } else {
            $this->log("There was an error updating the description of " . $paymentProcessor->getTransactionId(), $description);
        }

        // reset the session field
        Shopware()->Session()->paymillTransactionToken = null;

        $this->redirect(array("controller" => "checkout", "action" => "finish", "forceSecure" => 1));
    }

    /**
     * Uses the LoggingManager to insert a new entry into the Log
     *
     * @param String $merchantInfo      Information of use to the merchant
     * @param String $devInfo           Information of use to developers
     * @param String $devInfoAdditional Can be null
     */
    public function log($merchantInfo, $devInfo, $devInfoAdditional = null)
    {
        $loggingManager = new Shopware_Plugins_Frontend_PaymPaymentCreditcard_Components_LoggingManagerShopware();
        $loggingManager->write($merchantInfo, $devInfo, $devInfoAdditional);
    }

    /**
     * Redirects to the confirmationpage and sets an errormessage.
     */
    public function errorAction()
    {
        $errorMessage = null;
        if (isset(Shopware()->Session()->pigmbhErrorMessage)) {
            $errorMessage = 1;
        }

        $this->redirect(array("controller"   => "checkout", "action" => "confirm", "forceSecure" => 1,
                              "errorMessage" => $errorMessage));
    }
}