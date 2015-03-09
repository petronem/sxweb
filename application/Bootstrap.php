<?php

/*
    The contents of this file are subject to the Common Public Attribution License
    Version 1.0 (the "License"); you may not use this file except in compliance with
    the License. You may obtain a copy of the License at
    http://opensource.org/licenses/cpal_1.0. The License is based on the Mozilla
    Public License Version 1.1 but Sections 14 and 15 have been added to cover use
    of software over a computer network and provide for limited attribution for the
    Original Developer. In addition, Exhibit A has been modified to be consistent with
    Exhibit B.
    
    Software distributed under the License is distributed on an "AS IS" basis, WITHOUT
    WARRANTY OF ANY KIND, either express or implied. See the License for the
    specific language governing rights and limitations under the License.
    
    The Original Code is the SXWeb project.
    
    The Original Developer is the Initial Developer.
    
    The Initial Developer of the Original Code is Skylable Ltd (info-copyright@skylable.com). 
    All portions of the code written by Initial Developer are Copyright (c) 2013 - 2015
    the Initial Developer. All Rights Reserved.

    Contributor(s):    

    Alternatively, the contents of this file may be used under the terms of the
    Skylable White-label Commercial License (the SWCL), in which case the provisions of
    the SWCL are applicable instead of those above.
    
    If you wish to allow use of your version of this file only under the terms of the
    SWCL and not to allow others to use your version of this file under the CPAL, indicate
    your decision by deleting the provisions above and replace them with the notice
    and other provisions required by the SWCL. If you do not delete the provisions
    above, a recipient may use your version of this file under either the CPAL or the
    SWCL.
*/


/**
 * Preloads the User object, so the session will be correctly initialized
 */
require_once 'My/User.php';

/**
 * Main bootstrap class
 */
class Bootstrap extends Zend_Application_Bootstrap_Bootstrap {

    /**
     * Initializes the database.
     * 
     * The configuration options are taken from the skylable.ini config file.
     *
     * @return null|Zend_Db_Adapter_Abstract
     * @throws Exception
     */
    protected function _initDb() {
        $this->bootstrap('Config');
        $cfg = Zend_Registry::get('skylable');
        
        if (isset($cfg->db)) {
            $db_res = new Zend_Application_Resource_Db($cfg->db);
            $db = $db_res->getDbAdapter();
            $conn = $db->getConnection();
            if (is_null($conn)) {
                throw new Zend_Db_Exception('Internal error: database connection failed.');
            }
            return $db;
        }
        return NULL;
    }

    /**
     * Initializes the mail transport.
     * 
     * The configuration options are taken from the skylable.ini config file.
     * 
     * @return null|Zend_Application_Resource_Mail
     * @throws Zend_Exception
     */
    protected function _initMail() {
        $this->bootstrap('Config');
        $cfg = Zend_Registry::get('skylable');

        if (isset($cfg->mail)) {
            $mail = new Zend_Application_Resource_Mail($cfg->mail);
            return $mail;
        }
        return NULL;
    }

    /**
     * Initializes the application logger.
     *
     * Failback to a system logger on errors.
     *
     * @return Zend_Log
     * @throws Zend_Log_Exception
     */
    protected function _initLog() {
        $res = $this->getOption('resources');

        // Initializes the logger path
        if (isset($res['log']['stream']['writerParams']['stream'])) {
            if (!empty($res['log']['stream']['writerParams']['stream'])) {
                $path = dirname($res['log']['stream']['writerParams']['stream']);
                if (!@file_exists($path)) {
                    if (!@mkdir($path, 0775)) {
                        throw new Zend_Log_Exception('Internal error: failed to create log directory ' . $path);
                    }
                }
            }
        }

        if (array_key_exists('log', $res)) {
            $log_res = new Zend_Application_Resource_Log($res['log']);
            return $log_res->getLog();
        } else {
            $logger = new Zend_Log();
            $logger->addWriter(new Zend_Log_Writer_Syslog());
            return $logger;
        }
    }

    /**
     * Loads the main configuration file and save it into the Zend_Registry 
     * under the key 'skylable' 
     */
    protected function _initConfig() {
        // Loads the skylable configuration
        $cfg = new Zend_Config_Ini(realpath(APPLICATION_PATH . '/configs/skylable.ini'), APPLICATION_ENV);
        Zend_Registry::set('skylable', $cfg );
        return $cfg;
    }
   
    /**
     * Initializes the ACL.
     * 
     * @return My_Acl
     */
    protected function _initAcl() {
        $acl = new My_Acl();
        return $acl;
    }
    
    /**
     * Initializes all Front Controller plugins
     */
    protected function _initFrontControllerPlugins() {
        $this->bootstrap('FrontController');
        $front = $this->getResource('FrontController');
        
        /*
         * Setup the Acl plugin
         */
        $this->bootstrap('Acl');
        $acl = $this->getResource('Acl');
        
        $front->registerPlugin(new My_Plugin_Acl($acl));
    }
    
    /**
     * Initializes the view placeholders
     */
    protected function _initPlaceholders() {
        $this->bootstrap('View');
        $view = $this->getResource('View');
        
        $view->headTitle('SXWeb');
        $view->headTitle()->setSeparator(' / ');
    }
    
    /**
     * Initializes the Router
     */
    protected function _initRoutes() {
        $router = Zend_Controller_Front::getInstance()->getRouter();
        
        $router->addRoute('ping',  new Zend_Controller_Router_Route('ping', array( 'controller' => 'index', 'action' => 'ping' ) ));
        
        $router->addRoute('account_settings',  new Zend_Controller_Router_Route('account_settings', array( 'controller' => 'settings', 'action' => 'account' ) ));

        // Create account
        $router->addRoute('create', new Zend_Controller_Router_Route('create', array( 'controller' => 'index', 'action' => 'create') ));

         // Remove account
        $router->addRoute('remove_account', new Zend_Controller_Router_Route('remove/account', array('controller' => 'rmacc','action' => 'index')));

        // sendmail message / reset password
        $router->addRoute('recover', new Zend_Controller_Router_Route('reset/password', array( 'controller' => 'index', 'action' => 'resetpassword') ));

        // logout
        $router->addRoute('logout', new Zend_Controller_Router_Route('logout', array( 'controller' => 'index', 'action' => 'logout') ) );

        // Login
        $router->addRoute('login', new Zend_Controller_Router_Route('login', array( 'controller' => 'index','action' => 'login')) );
        
        // Edit account
        $router->addRoute('edit', new Zend_Controller_Router_Route('edit', array( 'controller' => 'index', 'action' => 'edit') ));

        // Upload
        $router->addRoute('upload2', new Zend_Controller_Router_Route_Regex( 'upload/(.+)', array( 'controller' => 'upload', 'action' => 'upload'	), array( 1 => 'vol' ) ) );
        $router->addRoute('upload', new Zend_Controller_Router_Route_Regex( 'upload/(.+)/(.+)', array( 'controller' => 'upload', 'action' => 'upload'),array(1 => 'vol',2 => 'url')));

        /*
         * sxls for AJAX
        */
        $router->addRoute('ajax',  new My_Regex( 'ajax/(.*)', array( 'controller' => 'upload','action' => 'ajax'), array( 1 => 'url' ) ) );

        /*
         * List files/directories + layout
        */
        $router->addRoute('vol',  new My_Regex( 'vol/(.*)', array( 'controller' => 'index', 'action' => 'index' ), array(1 => 'path') ) );

        /*
         * Download file from cluster
        */
        // $router->addRoute('open', new My_Regex( 'open/(.+)/(.+)', array( 'controller' => 'index', 'action' => 'open' ), array( 1 => 'vol', 2 => 'url' ) ));
        $router->addRoute('open', new Zend_Controller_Router_Route_Regex('open/(.+)', array( 'controller' => 'index', 'action' => 'open' ), array( 1 => 'path' ) ));
        $router->addRoute('preview', new My_Regex( 'preview/(.+)/(.+)', array( 'controller' => 'index', 'action' => 'open' ), array( 1 => 'vol', 2 => 'url' ) ));

        /*
         * Search
        */
        $router->addRoute('search', new My_Regex( 'search,(.*)', array( 'controller' => 'search', 'action' => 'index' ), array( 1 => 'vol'	)));
        $router->addRoute('suggest', new Zend_Controller_Router_Route( 'suggest', array( 'controller' => 'search', 'action' => 'suggest' ) ));

        /*
         * Pasword change
        */
        $router->addRoute('reset', new My_Regex( 'reset/password/(.*)', array( 'controller' => 'index', 'action' => 'reset' ), array( 1 => 'hash'	)));

        /*
        * use this if you cant pass mesage by flash/session
        */
        $router->addRoute('message', new My_Regex( 'message,(.*)', array( 'controller' => 'index', 'action' => 'login'), array( 1 => 'msg' )) );
        
        $router->addRoute('Activateaccount', new My_Regex( 'activate,(.+)', array( 'controller' => 'activateaccount', 'action' => 'index' ), array(1 => 'hash') ));

/*
 *================================================
 *       AJAX
 *       AjaxController 
 *================================================
 */
        // Create_dir
        $router->addRoute('create_dir', new Zend_Controller_Router_Route('create_dir', array( 'controller' => 'ajax', 'action' => 'createdir') ) );


        $router->addRoute('welcome',  new Zend_Controller_Router_Route('welcome', array( 'controller' => 'ajax', 'action' => 'welcome' ) ));

        $router->addRoute('window', new Zend_Controller_Router_Route_Regex('ajax/vol/(.+)', array( 'controller' => 'ajax', 'action' => 'window' ), array(1 => 'path')));

        $router->addRoute('copy', new Zend_Controller_Router_Route('copy', array( 'controller' => 'ajax', 'action' => 'copy')));

        $router->addRoute('move', new Zend_Controller_Router_Route('move', array( 'controller' => 'ajax', 'action' => 'move' ) ) );

        $router->addRoute('filelist', new Zend_Controller_Router_Route('filelist', array( 'controller' => 'ajax', 'action' => 'filelist' ) ));

        $router->addRoute('delete',  new Zend_Controller_Router_Route('delete', array( 'controller' => 'ajax', 'action' => 'delete' ) ));

        //rename
        $router->addRoute('rename', new Zend_Controller_Router_Route('rename', array( 'controller' => 'ajax','action' => 'rename' ) ));

        $router->addRoute('share', new Zend_Controller_Router_Route('share', array( 'controller' => 'ajax', 'action' => 'share' ) ));

        /*
        $route = new My_Regex(
                'shared/file/(.+)/(.+)',
                array(
                        'controller' => 'ajax',
                        'action'     => 'shared',
                ),
                array(
                   1 => 'key',
                   2 => 'file',
                )
        );*/
        $router->addRoute('shared', new Zend_Controller_Router_Route_Regex('shared/file/(.+)/(.+)', array( 'controller' => 'ajax', 'action' => 'shared' ), array( 1 => 'key', 2 => 'file' ) ));

        $toAction = new Zend_Controller_Router_Route('api/share',
                array(
                        'module' => 'share',
                        'controller' => 'index',
                        'action' => 'share'
                )
        );

        $router->addRoute('android_share',$toAction);

        // Demo mode route
        $router->addRoute('demo', new Zend_Controller_Router_Route('demo', array( 'controller' => 'index', 'action' => 'demo' ) ));
    }
}

