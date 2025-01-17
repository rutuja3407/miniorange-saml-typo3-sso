<?php

namespace Miniorange\MiniorangeSaml\Controller;

use Exception;
use Miniorange\Helper\Constants;
use PDO;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Miniorange\Helper\CustomerSaml;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Miniorange\Helper\Utilities;


/**
 * BesamlController
 */
class BesamlController extends ActionController
{
    private $myjson = null;

    private $spobject = null;

    protected $fesaml = null;

    protected $response = null;

    protected $tab = "";

    /**
     * @var TYPO3\CMS\Extbase\Object\ObjectManagerInterface
     */
    protected $objectManager;

	/**
	 * @throws Exception
	 */
    public function requestAction()
    {
        error_log("in besamlController");
        session_start();
        $util=new Utilities();
        $baseurl= $util->currentPageUrl();
        $_SESSION['base_url']=$baseurl;
        
        if(isset($_SESSION['flag']) && $_SESSION['flag']=='set')
        {
            $_POST= $_SESSION;
            $check_content=$_SESSION['check_content'];

        if(isset($_SESSION['error']) && $_SESSION['error']=='different password')
        { 
            Utilities::showSuccessFlashMessage('Please enter same password in both password fields');
            unset($_SESSION['error']);
            unset($_SESSION['flag']);
        }
        }

        //------------ VERIFY CUSTOMER---------------

        if ( isset( $_POST['option'] ) and $_POST['option'] == "mo_verify_customer" ) {

			$this->account($_POST);
        }

//------------ HANDLE LOG OUT ACTION---------------
            elseif(isset($_POST['option']) and $_POST['option']== 'logout'){
                
                    $this->remove_cust();
                    
                    Utilities::showSuccessFlashMessage('Logged out successfully.');
                    $this->view->assign('status','not_logged');
                   
                    
            }
//------------ IDENTITY PROVIDER SETTINGS---------------
        if(isset($_POST['option']) and $_POST['option'] == 'idp_settings'){
        	$value1 = $this->validateURL($_POST['saml_login_url']);
            $value2 = $this->validateURL($_POST['idp_entity_id']);
            $value3 = Utilities::check_certificate_format($_POST['x509_certificate']);
            
            if($value1 == 1 && $value2 == 1 && $value3 == 1)
            {
                $obj = new BesamlController();
                $obj->storeToDatabase($_POST);
				Utilities::showSuccessFlashMessage('IDP Setting saved successfully.');
            }else{
                if ($value3 == 0) {
                	  Utilities::showErrorFlashMessage('Incorrect Certificate Format');
                }else {
                	 Utilities::showErrorFlashMessage('Blank Field or Invalid input');
                }
            }
        }

//------------ HANDLING SUPPORT QUERY---------------
        elseif ( isset( $_POST['option'] ) and $_POST['option'] == "mo_contact_us_query_option" ) {
            $this->support();
        }

//------------ SERVICE PROVIDER SETTINGS---------------
            elseif(isset($_POST['option']) and $_POST['option'] == 'save_sp_settings') {
                $value1 = $this->validateURL($_POST['site_base_url']);
                $value2 = $this->validateURL($_POST['acs_url']);
                $value3 = $this->validateURL($_POST['sp_entity_id']);

                if($value1 == 1 && $value2 == 1 && $value3 == 1)
                {
                    if($this->fetch('uid') == null){
                        $this->save('uid',1,'saml');
                    }
                    $this->defaultSettings($_POST);
                    Utilities::showSuccessFlashMessage('SP Setting saved successfully.');

                }else{
                      Utilities::showErrorFlashMessage('Incorrect Input');
                }
            }


        //GROUP MAPPINGS
        elseif(isset($_POST['option']) and $_POST['option'] == 'group_mapping'){
            Utilities::updateTable(Constants::COLUMN_GROUP_DEFAULT, $_POST['defaultUserGroup'],Constants::TABLE_SAML);
            Utilities::showSuccessFlashMessage('Default Group saved successfully.');
        }    

//------------ CHANGING TABS---------------
        if($_POST['option'] == 'save_sp_settings' )
        {
            $this->tab = "Service_Provider";
        }
        elseif ($_POST['option'] == 'attribute_mapping')
        {
            $this->tab = "Attribute_Mapping";
        }
        elseif ($_POST['option'] == 'group_mapping')
        {
            $this->tab = "Group_Mapping";
        }
        elseif ($_POST['option'] == 'get_premium')
        {
            $this->tab = "Premium";
        }
        elseif($_POST['option']=='idp_settings')
        {
            $this->tab = "Identity_Provider";
        }
        elseif($_POST['option']=='mo_contact_us_query_option')
        {
            $this->tab = "Support";
        }
        else
        {
            $this->tab = "Account";
        }

        $this->objectManager = GeneralUtility::makeInstance('TYPO3\\CMS\\Extbase\\Object\\ObjectManager');
        $allUserGroups= $this->objectManager->get('TYPO3\\CMS\\Extbase\\Domain\\Repository\\FrontendUserGroupRepository')->findAll();
        $allUserGroups->getQuery()->getQuerySettings()->setRespectStoragePage(false);
        $this->view->assign('allUserGroups', $allUserGroups);
        $this->view->assign('defaultGroup',Utilities::fetchFromTable(Constants::COLUMN_GROUP_DEFAULT,Constants::TABLE_SAML));

//------------ LOADING SAVED SETTINGS OBJECTS TO BE USED IN VIEW---------------
        $this->view->assign('conf_idp', json_decode($this->fetch('object'), true));
        $this->view->assign('conf_sp', json_decode($this->fetch('spobject'), true));

//------------ LOADING VARIABLES TO BE USED IN VIEW---------------
        if($this->fetch_cust(Constants::CUSTOMER_REGSTATUS) == 'logged'){
					$this->view->assign('status','logged');
					$this->view->assign('log', '');
                    $this->view->assign('nolog', 'display:none');
					$this->view->assign('email',$this->fetch_cust('cust_email'));
					$this->view->assign('key',$this->fetch_cust('cust_key'));
					$this->view->assign('token',$this->fetch_cust('cust_token'));
					$this->view->assign('api_key',$this->fetch_cust('cust_api_key'));
                   
        }else{
					$this->view->assign('log', 'disabled');
                    $this->view->assign('nolog', 'display:block');
					$this->view->assign('status','not_logged');
        }
        
        $this->view->assign('tab', $this->tab);
        $this->view->assign('extPath', Utilities::getExtensionRelativePath());
        $this->cacheService->clearPageCache([$GLOBALS['TSFE']->id]);
    }

    public function save($column,$value,$table)
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $affectedRows = $queryBuilder->insert($table)->values([ $column => $value, ])->execute();
    }

//  LOGOUT CUSTOMER
    public function remove_cust(){
        $this->update_cust('cust_key','');
        $this->update_cust('cust_api_key','');
        $this->update_cust('cust_token','');
        $this->update_cust('cust_reg_status', '');
        $this->update_cust('cust_email','');

//        $this->update_saml_setting('idp_name',"");
//        $this->update_saml_setting('idp_entity_id',"");
//        $this->update_saml_setting('saml_login_url',"");
//		  $this->update_saml_setting('saml_logout_url',"");
//        $this->update_saml_setting('x509_certificate',"");
//        $this->update_saml_setting('login_binding_type',"");
//        $this->update_saml_setting('object',"");
    }

//    VALIDATE CERTIFICATE
    public function validate_cert($saml_x509_certificate)
    {

		$certificate = openssl_x509_parse ( $saml_x509_certificate);

        foreach( $certificate as $key => $value ) {
            if ( empty( $value ) ) {
                unset( $saml_x509_certificate[ $key ] );
                return 0;
            } else {
                $saml_x509_certificate[ $key ] = $this->sanitize_certificate( $value );
                if ( ! @openssl_x509_read( $saml_x509_certificate[ $key ] ) ) {
                    return 0;
                }
            }
        }

        if ( empty( $saml_x509_certificate ) ) {
            return 0;
        }

        return 1;
    }

//    VALIDATE URLS
    public function validateURL($url)
    {
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return 1;
        } else {
            return 0;
        }
    }

    public function mo_is_curl_installed() {
        if ( in_array( 'curl', get_loaded_extensions() ) ) {
            return 1;
        } else {
            return 0;
        }
    }

//   HANDLE LOGIN FORM
    public function account($post){
        error_log(print_r($_SESSION['flag'],true));
        if(isset($_SESSION['flag']) && $_SESSION['flag']=='set')
        {

            $email = $post['email'];
            $password = $post['password'];
            $check_content=$_POST['check_content'];
            $key_content=$_POST['key_content'];
            $result=$_POST['result'];
            
                                 
            if($key_content['status'] == 'SUCCESS' && $check_content['status']=='SUCCESS')
            {
                $this->save_customer($key_content,$email);
                Utilities::showSuccessFlashMessage('User retrieved successfully.');
            }elseif($key_content['status'] == 'SUCCESS'){
                $this->save_customer($key_content,$email);
                Utilities::showSuccessFlashMessage('Customer created successfully.');
            }else{
                Utilities::showErrorFlashMessage('This is not a valid email. Please enter a valid email.');
            }
             $_SESSION['flag']='unset';

        }
    }

//  SAVE CUSTOMER
    public function save_customer($content,$email){
        $this->update_cust('cust_key',$content['id']);
        $this->update_cust('cust_api_key',$content['apiKey']);
        $this->update_cust('cust_token',$content['token']);
        $this->update_cust('cust_reg_status', 'logged');
        $this->update_cust('cust_email',$email);
    }

// FETCH CUSTOMER
    public function fetch_cust($col)
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('customer');
        $variable = $queryBuilder->select($col)->from('customer')->where($queryBuilder->expr()->eq('id', $queryBuilder->createNamedParameter(1, PDO::PARAM_INT)))->execute()->fetchColumn(0);
        return $variable;
    }

// ---- UPDATE CUSTOMER Details
	  public function update_cust($column,$value)
    {
        if($this->fetch_cust('id') == null)
        {
            $this->insertValue();
        }
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('customer');
        $queryBuilder->update('customer')->where($queryBuilder->expr()->eq('id', $queryBuilder->createNamedParameter(1, PDO::PARAM_INT)))->set($column, $value)->execute();
    }

// ---- UPDATE SAML Settings
public function update_saml_setting($column, $value)
{
    $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('saml');
    $queryBuilder->update('saml')->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter(1, PDO::PARAM_INT)))->set($column, $value)->execute();
}

  public function insertValue()
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('customer');
        $affectedRows = $queryBuilder->insert('customer')->values([  'id' => '1' ])->execute();
    }

// --------------------SUPPORT QUERY---------------------
	public function support(){
        if(!$this->mo_is_curl_installed() ) {
            error_log("error");
              Utilities::showErrorFlashMessage('ERROR: <a href="http://php.net/manual/en/curl.installation.php" 
                       target="_blank">PHP cURL extension</a> is not installed or disabled. Query submit failed.');
            return;
        }
        // Contact Us query
        $_POST['email']=$_SESSION['email'];
        $email    = $_POST['email'];
        $phone    = $_POST['mo_contact_us_phone'];
        $query    = $_POST['mo_contact_us_query'];
        if($this->mo_check_empty_or_null( $email ) || $this->mo_check_empty_or_null( $query ) ) {
            error_log("enter valid email");
            $_SESSION['support_response']='invalid id';
          Utilities::showErrorFlashMessage('Please enter a valid Email address. ');
        }elseif(!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            error_log("enter valid email");
              Utilities::showErrorFlashMessage('Please enter a valid Email address. ');
        }else {
            $submitted = json_decode(Utilities::submit_contact( $email, $phone, $query ), true);
            error_log("submitted here".print_r($submitted,true));
                    if ( $submitted['status'] == 'SUCCESS' ) {
                        error_log("support query sent");
                        error_log('support_response in function'.print_r($_SESSION['support_response'],true));
                        Utilities::showSuccessFlashMessage('Support query sent ! We will get in touch with you shortly.');
                    }else{
                        error_log("could not send query. Try again later");
                      Utilities::showErrorFlashMessage('could not send query. Please try again later or mail us at info@miniorange.com');
                    }
        }
    }

	/**
	 * @param $var
	 * @return bool|string
	 */
    public function fetch($var){
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('saml');
        $variable = $queryBuilder->select($var)->from('saml')->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter(1, PDO::PARAM_INT)))->execute()->fetchColumn(0);
        return $variable;
    }

    public function mo_check_empty_or_null( $value ) {
        if( ! isset( $value ) || empty( $value ) ) {
            return true;
        }
        return false;
    }

    /**
     * @param $postArray
     */
    public function defaultSettings($postArray)
    {
        error_log("post array in defaultSettings: ".print_r($postArray,true));
		$this->spobject = json_encode($postArray);
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('saml');
        $queryBuilder->update('saml')->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter(1, PDO::PARAM_INT)))->set('sp_entity_id', $postArray['sp_entity_id'])->execute();
        $queryBuilder->update('saml')->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter(1, PDO::PARAM_INT)))->set('site_base_url', $postArray['site_base_url'])->execute();
        $queryBuilder->update('saml')->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter(1, PDO::PARAM_INT)))->set('acs_url', $postArray['acs_url'])->execute();
		$queryBuilder->update('saml')->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter(1, PDO::PARAM_INT)))->set('slo_url', $postArray['slo_url'])->execute();
        $queryBuilder->update('saml')->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter(1, PDO::PARAM_INT)))->set('fesaml', $postArray['fesaml'])->execute();
        $queryBuilder->update('saml')->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter(1, PDO::PARAM_INT)))->set('response', $postArray['response'])->execute();
        $queryBuilder->update('saml')->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter(1, PDO::PARAM_INT)))->set('spobject', $this->spobject)->execute();
    }

    /**
     * @param $creds
     */
    public function storeToDatabase($creds)
    {

        $this->myjson = json_encode($creds);

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('saml');
        $uid = $queryBuilder->select('uid')->from('saml')
						                     ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter(1, PDO::PARAM_INT)))
						                     ->execute()->fetchColumn(0);

        error_log("If Previous_IdP configured : ".$uid);

        if ($uid == null) {
            error_log("No Previous_IdP found : ".$uid);
            $affectedRows = $queryBuilder
                ->insert('saml')
                ->values([
                    'uid' => $queryBuilder->createNamedParameter(1, PDO::PARAM_INT),
                    'idp_name' => $creds['idp_name'],
                    'idp_entity_id' => $creds['idp_entity_id'],
                    'saml_login_url' => $creds['saml_login_url'],
                    'saml_logout_url' => $creds['saml_logout_url'],
                    'x509_certificate' => $creds['x509_certificate'],
                    'force_authn' => $creds['force_authn'],
                    'login_binding_type' => Constants::HTTP_REDIRECT,
                    'object' => $this->myjson,])
                ->execute();
            error_log("affected rows ".$affectedRows);
        }else {

            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('saml');
            $queryBuilder->update('saml')->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter(1, PDO::PARAM_INT)))
                ->set('idp_name', $creds['idp_name'])->execute();
            $queryBuilder->update('saml')->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter(1, PDO::PARAM_INT)))
                ->set('idp_entity_id', $creds['idp_entity_id'])->execute();
            $queryBuilder->update('saml')->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter(1, PDO::PARAM_INT)))
                ->set('saml_login_url', $creds['saml_login_url'])->execute();
            $queryBuilder->update('saml')->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter(1, PDO::PARAM_INT)))
                ->set('saml_logout_url', $creds['saml_logout_url'])->execute();
            $queryBuilder->update('saml')->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter(1, PDO::PARAM_INT)))
                ->set('login_binding_type', Constants::HTTP_REDIRECT)->execute();
            $queryBuilder->update('saml')->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter(1, PDO::PARAM_INT)))
                ->set('x509_certificate', $creds['x509_certificate'])->execute();
            $queryBuilder->update('saml')->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter(1, PDO::PARAM_INT)))
                ->set('object', $this->myjson)->execute();
        }
    }
}
