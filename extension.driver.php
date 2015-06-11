<?php

require_once dirname(__FILE__) . '/lib/class.freeagent.php';

/**
 * Extension_Freeagent
 *
 * @uses Extension
 * @package Symphony\Extensions\Freeagent
 * @version 1.0
 */
 
class Extension_freeagent extends Extension
{

    /**
     *  Default configuration
     *  @var Array
     */
    public static $defaults = array(
        'url'         	=> 'https://api.freeagent.com/v2/',
        'url-sandbox'	=> 'https://api.sandbox.freeagent.com/v2/',
        'app-id' 		=> '',
        'app-token' 	=> '',
        'development' 	=> 'yes'
    );
    
    /**
     * freeagent
     *
     * @var freeagent
     * @access protected
     */
     
    protected $freeagent;

    /**
     * getSubscribedDelegates
     *
     * @see Toolkit\Extension::getSubscribedDelegates()
     * @access public
     * @return void
     */
    public function getSubscribedDelegates()
    {
        return array(
            array(
                'page' => '/system/preferences/',
                'delegate' => 'AddCustomPreferenceFieldsets',
                'callback' => 'appendPreferences'
            ),
            array(
                'page' => '/system/preferences/',
                'delegate' => 'Save',
                'callback' => 'savePreferences'
            ),
        );
    }


    /**
     * install
     *
     * @access public
     * @return void
     */
    public function install()
    {
    	
    	Symphony::Configuration()->setArray(array('freeagent' => self::$defaults));
    	Symphony::Configuration()->write();
    
		return Symphony::Database()->import("
			DROP TABLE IF EXISTS `tbl_freeagent`;
			CREATE TABLE IF NOT EXISTS `tbl_freeagent` (
				`app_id` VARCHAR(255) NOT NULL,
				`token_access` VARCHAR(255) NULL,
				`token_refresh` VARCHAR(255) NULL,
				`sales_tax_rate` FLOAT NULL,
				`token_expire` TIMESTAMP NULL,
				PRIMARY KEY (`app_id`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
		");

    }

    /**
     * uninstall
     *
     * @access public
     * @return void
     */
    public function uninstall()
    {
        Symphony::Configuration()->remove('freeagent');
        
        try {
			Symphony::Database()->query("DROP TABLE IF EXISTS `tbl_freeagent`;");
		}catch (Exception $e) {
			return false;
		}
        
        return Symphony::Configuration()->write();
    }

    /**
     * appendPreferences
     *
     * @param Mixed $context
     * @access public
     * @return void
     */
    public function appendPreferences($context)
    {
    	
    	$conf = Symphony::Configuration()->get('freeagent');
    	$app = reset(Symphony::Database()->fetch("SELECT app_id, sales_tax_rate FROM tbl_freeagent WHERE app_id = '".$conf["app-id"]."' LIMIT 1"));
    	
    	//Proces retrn code
    	if (isset($_GET['code'])){
    	
    		$client = new Freeagent();
    	
			// now exchange the code for an access token - you should save this for future usage
			$accessToken = $client->getAccessToken($_GET['code'], SYMPHONY_URL.'/system/preferences/');

			if(isset($accessToken)){
			
				if($app == false){
					Symphony::Database()->query("INSERT INTO tbl_freeagent (app_id, token_access, token_refresh, token_expire ) VALUES ('".$conf["app-id"]."', '".$accessToken->access_token."', '".$accessToken->refresh_token."', '".date("Y-m-d H:i:s", time() + $accessToken->expires_in)."')");
					Symphony::Log()->pushToLog('Freeagent' . ': ' . ' Connected.' , 100, true);
					$app = true;
				} else {
					Symphony::Database()->query("UPDATE tbl_freeagent SET `token_access`='".$accessToken->access_token."', `token_refresh`='".$accessToken->refresh_token."', `token_expire`='".date("Y-m-d H:i:s", time() + $accessToken->expires_in)."' WHERE `app_id` = '".$conf["app-id"]."'");
					Symphony::Log()->pushToLog('Freeagent' . ': ' . ' Reconnected.' , 100, true);
				}
	        
				Administration::instance()->Page->pageAlert(__('Freeagent Connected.'), Alert::SUCCESS);
			
			}
			
		}
    	
    
    	//Retrieve Freeagent Token
    	if (isset($_REQUEST['action']['freeagent-connect'])){
    	
    		$client = new Freeagent();

        	// get the authorisation url we need to pass customer to, passing the url you want them returned to (This url)
			$authoriseURL = $client->getAuthoriseURL(SYMPHONY_URL.'/system/preferences/');
        
			header('Location: '.$authoriseURL);
			exit();
			
		}
    
		//Build Preferences UI
    
        extract($context);

        $fieldset = new XMLElement('fieldset', null, array(
            'class' => 'settings',
            'id' => $this->name
        ));

        $legend = new XMLElement('legend', 'Freeagent');
        $fieldset->appendChild($legend);

        $div = new XMLElement('div', null, array(
            'class' => 'contents'
        ));

        $div = new XMLElement('div', NULL, array('class' => 'group'));

        $label = Widget::Label(__('OAuth Identifier'), Widget::Input('settings[freeagent][app-id]',
            isset($conf['app-id']) ? $conf['app-id'] : static::$defaults['app-id'], 'text')
        );

        $div->appendChild($label);

        $label = Widget::Label(__('OAuth Secret'), Widget::Input('settings[freeagent][app-token]',
            isset($conf['app-token']) ? $conf['app-token'] : static::$defaults['app-token'], 'password')
        );

        $div->appendChild($label);
        $fieldset->appendChild($div);
        
        //Button
        
        $div = new XMLElement('span', null, array(
            'class' => 'frame'
        ));
        
        if($app == false){
        	$div->appendChild(new XMLElement('button', __('Connect'), array_merge(array('name' => 'action[freeagent-connect]', 'type' => 'submit'))));
        }else{
        	$div->appendChild(new XMLElement('button', __('Reconnect'), array_merge(array('name' => 'action[freeagent-connect]', 'type' => 'submit'))));
        }

        $fieldset->appendChild($div);
        
        //Checkbox
        
        $hidden = Widget::Input('settings[freeagent][development]', 'no', 'hidden');
        $fieldset->appendChild($hidden);

        $label = Widget::Label(null,
            Widget::Input('settings[freeagent][development]', 'yes', 'checkbox',
                (isset($conf['development']) && $conf['development'] === 'yes') ? array('checked' => 'checked') : array())
        );
        
        
        $label->setValue(__('Development Mode'), false);

        $help = new XMLElement('p', __('Use the Freeagent sandbox environment.'), array('class' => 'help'));

		$fieldset->appendChild($label);
        $fieldset->appendChild($help);
        
        
        if($app == true){
        
	        $label = Widget::Label(__('Sales Tax Rate'), Widget::Input('settings[freeagent][sales-tax-rate]',
	            $app['sales_tax_rate'], 'text')
	        );
	
	        $fieldset->appendChild($label);
	        
	    }
        
        

        $wrapper->appendChild($fieldset);
    }

    /**
     * savePreferences
     *
     * @param Mixed $context
     * @param Mixed $override
     * @access public
     * @return void
     */
    public function savePreferences($context, $override = false)
    {
    
    	$conf = Symphony::Configuration()->get('freeagent');
    	$app = reset(Symphony::Database()->fetch("SELECT app_id, sales_tax_rate FROM tbl_freeagent WHERE app_id = '".$conf["app-id"]."' LIMIT 1"));
    
        foreach ($context['settings']['freeagent'] as $key => $val) {
        
        	if($key == "sales-tax-rate" && $app == true){
        		Symphony::Database()->query("UPDATE tbl_freeagent SET `sales_tax_rate`='".$val."' WHERE `app_id` = '".$app["app_id"]."'");
        	}else{
            	Symphony::Configuration()->set($key, $val, 'freeagent');
            }
        }
        Symphony::Configuration()->write();
    }

}
