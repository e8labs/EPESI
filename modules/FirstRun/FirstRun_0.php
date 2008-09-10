<?php
/**
 * @author Paul Bukowski <pbukowski@telaxus.com>
 * @copyright Copyright &copy; 2006, Telaxus LLC
 * @version 0.9
 * @license SPL
 * @package epesi-firstrun
 * @subpackage first-run
 */
defined("_VALID_ACCESS") || die('Direct access forbidden');

class FirstRun extends Module {
	private $ini;

	public function body() {
		$th = & $this->init_module('Base/Theme');
		ob_start();
		print('<center>');
		$post_install = & $this->get_module_variable('post-install',array());
		if(ModuleManager::is_installed('Base')>=0 || !empty($post_install)) {
			foreach($post_install as $i=>$v) {
				$i = str_replace('/','_',$i);
				ModuleManager::include_install($i);
				$f = array($i.'Install','post_install');
				$fs = array($i.'Install','post_install_process');
				if(!is_callable($f) || !is_callable($fs)) {
					unset($post_install[$i]);
					continue;
				}
				$ret = call_user_func($f);
				$form = $this->init_module('Libs/QuickForm',null,$i);
				$form->addElement('header',null,'Post installation of '.str_replace('_','/',$i));
				$form->add_array($ret);
				$form->addElement('submit',null,'OK');
				if($form->validate()) {
					$form->process($fs);
					unset($post_install[$i]);
				} else {
					$form->display();
					break;
				}
			}
			if(ModuleManager::is_installed('Base')>=0 && empty($post_install)) {
				Variable::set('default_module','Base_Box');
				Epesi::redirect();
			}
		} else {

			$wizard = & $this->init_module('Utils/Wizard');
			$this->lang = & $this->init_module('Base/Lang');

			/////////////////////////////////////////////////////////////
			$this->ini = parse_ini_file('modules/FirstRun/distros.ini',true);
			$f = & $wizard->begin_page();
			$f->addElement('header', null, $this->lang->t('Welcome to epesi first run wizard'));
			$f->setDefaults(array('setup_type'=>key($this->ini)));
			foreach($this->ini as $name=>$pkgs)
				$f->addElement('radio', 'setup_type', '', $this->lang->t($name), $name);
			$wizard->next_page();

			/////////////////////////////////////////////////////////////////
			$f = & $wizard->begin_page('simple_user');
			$f->addElement('header', null, $this->lang->t('Please enter administrator user login and password'));

			$f->addElement('text', 'login', $this->lang->t('Login'));
			$f->addRule('login', $this->lang->t('A username must be between 3 and 32 chars'), 'rangelength', array(3,32));
			$f->addRule('login', $this->lang->t('Field required'), 'required');

			$f->addElement('text', 'mail', $this->lang->t('e-mail'));
			$f->addRule('mail', $this->lang->t('Field required'), 'required');
			$f->addRule('mail', $this->lang->t('This isn\'t valid e-mail address'), 'email');

			$f->addElement('password', 'pass', $this->lang->t('Password'));
			$f->addElement('password', 'pass_c', $this->lang->t('Confirm password'));
			$f->addRule('pass', $this->lang->t('Field required'), 'required');
			$f->addRule('pass_c', $this->lang->t('Field required'), 'required');
			$f->addRule(array('pass','pass_c'), $this->lang->t('Passwords don\'t match'), 'compare');
			$f->addRule('pass', $this->lang->t('Your password must be longer then 5 chars'), 'minlength', 5);

			$wizard->next_page();

			/////////////////////////////////////////////////////
			$f = & $wizard->begin_page('simple_mail');

			$f->setDefaults(array('mail_method'=>'mail'));
			$f->addElement('header',null, $this->lang->t('Mail settings'));
			$f->addElement('html','<tr><td colspan=2>'.$this->lang->t('If you are on a hosted server it probably should stay as it is now.').'</td></tr>');
			$f->addElement('select','mail_method', $this->lang->t('Choose method'), array('smtp'=>'remote smtp server', 'mail'=>'local php.ini settings'));

			$wizard->next_page(array($this,'choose_mail_method'));

			//////////////////////
			$f = & $wizard->begin_page('simple_mail_smtp');

			$f->addElement('header',null, $this->lang->t('Mail settings'));
			$f->addElement('text','mail_host', $this->lang->t('SMTP host address'));
			$f->addRule('mail_host', $this->lang->t('Field required'),'required');

			$f->addElement('header',null, $this->lang->t('If your server needs authorization...'));
			$f->addElement('text','mail_user', $this->lang->t('Login'));
			$f->addElement('password','mail_password', $this->lang->t('Password'));

			$wizard->next_page();

			////////////////////////////////////////////////////////////
			$f = & $wizard->begin_page('setup_warning');
			$f->addElement('header', null, $this->lang->t('Warning'));
			$f->addElement('html','<tr><td colspan=2><br />Setup will now check for available modules and will install them.<br>This operation may take several minutes.<br><br></td></tr>');
			$wizard->next_page();

			/////////////////////////////////////////
			$this->display_module($wizard, array(array($this,'done')));
		}
		print('</center>');
		$th->assign('wizard',ob_get_clean());
		$th->display();
	}

	public function choose_mail_method($d) {
		if($d['mail_method']=='mail') return 'setup_warning';
		return 'simple_mail_smtp';
	}

	public function done($d) {
		@set_time_limit(0);
		$pkgs = isset($this->ini[$d[0]['setup_type']]['package'])?$this->ini[$d[0]['setup_type']]['package']:array();
		if(!ModuleManager::install('Base')) {
			print('Unable to install Base module pack.');
			return false;
		}
		foreach($pkgs as $p)
			if(!ModuleManager::install(str_replace('/','_',$p))) {
				print('<b>Unable to install '.str_replace('_','/',$p).' module.</b>');
			}

		Base_SetupCommon::refresh_available_modules();
		Base_ThemeCommon::create_cache();

		if(!Base_UserCommon::add_user($d['simple_user']['login'])) {
		    	print('Unable to create user');
		    	return false;
		}

		$user_id = Base_UserCommon::get_user_id($d['simple_user']['login']);
		if($user_id===false) {
		    print('Unable to get admin user id');
		    return false;
		}

		if(!DB::Execute('INSERT INTO user_password(user_login_id,password,mail) VALUES(%d,%s, %s)', array($user_id, md5($d['simple_user']['pass']), $d['simple_user']['mail']))) {
		   	print('Unable to set user password');
		    	return false;
		}

		if(!Base_AclCommon::change_privileges($user_id, array(Base_AclCommon::sa_group_id()))) {
			print('Unable to update admin account data (groups).');
			return false;
		}

		Acl::set_user($user_id);
		Variable::set('anonymous_setup',false);

		$method = $d['simple_mail']['mail_method'];
		Variable::set('mail_method', $method);
		Variable::set('mail_from_addr', $d['simple_user']['mail']);
		Variable::set('mail_from_name', $d['simple_user']['login']);
		if($method=='smtp') {
			Variable::set('mail_host', $d['simple_mail_smtp']['mail_host']);
			if($d['simple_mail_smtp']['mail_user']!=='' && $d['simple_mail_smtp']['mail_user']!=='')
				$auth = true;
			else
				$auth = false;
			Variable::set('mail_auth', $auth);
			if($auth) {
				Variable::set('mail_user', $d['simple_mail_smtp']['mail_user']);
				Variable::set('mail_password', $d['simple_mail_smtp']['mail_password']);
			}
		}

		$processed = ModuleManager::get_processed_modules();

		$this->set_module_variable('post-install',$processed);
		location();
	}

}

?>
