<?php
/**
 * @author Arkadiusz Bisaga <abisaga@telaxus.com>
 * @copyright Copyright &copy; 2008, Telaxus LLC
 * @license MIT
 * @version 1.0
 * @package epesi-utils
 * @subpackage CurrencyField
 */
defined("_VALID_ACCESS") || die('Direct access forbidden');

use Money\Currency;
use Money\Money;
use Money\Formatter\IntlMoneyFormatter;
use Money\Currencies\ISOCurrencies;
use peterkahl\locale\locale;

class Utils_CurrencyFieldCommon extends ModuleCommon {
	public static function format($val, $currency=null) {
        if (!isset($currency) || !$currency) {
            $val = self::get_values($val);
            $currency = $val[1];
            if (!$currency) return '';
            $val = $val[0];
        }
        $code = Utils_CurrencyFieldCommon::get_code((int)$currency);
        $val = (int)(floatval($val) * 100);
        $locale = locale::country2locale(Base_RegionalSettingsCommon::get_default_location()['country']);
        // here condition for taking the first argument, because
        // e.g. for US is returns en_US and es_US
        if (strpos($locale, ',') !== false) {
            $locale = explode(',', $locale)[0];
        }
        $money = new Money($val, new Currency($code));
        $number_formatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
        $money_formatter = new IntlMoneyFormatter($number_formatter, new ISOCurrencies());
        return $money_formatter->format($money);
	}

    public static function is_empty($p) {
        return strpos($p,'__') === 0;
    }
	
	public static function get_values($p) {
		if (!is_array($p)) $p = explode('__', $p);
                if(!is_numeric($p[0]) && $p[0]!='') return false;
		if (!isset($p[1])) $p[1] = Base_User_SettingsCommon::get('Utils_CurrencyField', 'default_currency');
        $p[0] = str_replace(array(',', self::get_decimal_point($p[1])), '.', $p[0]);
		return $p;
	}
	
	public static function format_default($v, $c=null) {
        $values = ($c === null ? self::get_values($v) : self::get_values(array($v, $c)));
        $c = $values[1];
        $v = round($values[0], self::get_precission($c));
		return $v.'__'.$c;
	}

	public static function user_settings() {
		$currency_options = DB::GetAssoc('SELECT id, code FROM utils_currency WHERE active=1');
		$def = self::get_default_currency();
		return array(__('Regional Settings')=>array(
				array('name'=>'currency_header', 'label'=>__('Currency'), 'type'=>'header'),
				array('name'=>'default_currency','label'=>__('Default currency'),'type'=>'select','values'=>$currency_options,'default'=>$def['id']),
					));
	}
	
	public static function get_decimal_point($arg = null) {
        self::load_cache();
		if($arg===null) $arg = Base_User_SettingsCommon::get('Utils_CurrencyField','default_currency');
		if (!isset(self::$cache[$arg])) return null;
		return self::$cache[$arg]['decimal_sign'];
	}
	
	public static function get_thousand_point($arg) {
        self::load_cache();
		if (!isset(self::$cache[$arg])) return null;
		return self::$cache[$arg]['thousand_sign'];
	}
	
	public static function get_id_by_code($code) {
		static $cache;
		if(!isset($cache)) $cache = array();
		if(!isset($cache[$code]))
			$cache[$code] = DB::GetOne('SELECT id FROM utils_currency WHERE code=%s', array($code));
		return $cache[$code];
	}
	
	public static function get_code($arg) {
        self::load_cache();
		if (!isset(self::$cache[$arg])) return null;
		return self::$cache[$arg]['code'];
	}
	
	public static function get_precission($arg) {
        self::load_cache();
		if (!isset(self::$cache[$arg])) return null;
		return self::$cache[$arg]['decimals'];
	}
	
	public static function get_currencies() {
		static $cache=null;
		if ($cache===null) $cache = DB::GetAssoc('SELECT id, code FROM utils_currency WHERE active=1');
		return $cache;
	}

	public static function get_all_currencies() {
		static $cache=null;
		if ($cache===null) $cache = DB::GetAssoc('SELECT id, code FROM utils_currency');
		return $cache;
	}
	
	public static function get_default_currency() {
		static $cache=null;
		if ($cache===null) $cache = DB::GetRow('SELECT * FROM utils_currency WHERE default_currency=1');
		return $cache;
	}

    public static function get_default_cryptocurrency() {
        static $cache=null;
        if ($cache===null) $cache = DB::GetRow('SELECT * FROM utils_cryptocurrencies WHERE default_currency=1');
        return $cache;
    }
	
	public static function admin_caption() {
		return array('label'=>__('Currencies'), 'section'=>__('Regional Settings'));
	}
	
	public static function get_symbol($arg) {
        self::load_cache();
		if (!isset(self::$cache[$arg])) return null;
		return self::$cache[$arg]['symbol'];
	}
	public static function get_symbol_position($arg) {
        self::load_cache();
		if (!isset(self::$cache[$arg])) return null;
		return self::$cache[$arg]['pos_before'];
	}

    /**
     * Parse currency using existing currencies set in the system.
     *
     * @param $string Currency string to parse
     *
     * @return array|null null on failure and array(value, currency_id) on success - like get_values returns.
     */
    public static function parse_currency($string) {
        $string = html_entity_decode($string);
        $string = preg_replace('/[\pZ\pC\s]/u', '', $string); // remove whitespaces, including unicode nbsp
        $currencies = Utils_CurrencyFieldCommon::get_currencies();
        foreach (array_keys($currencies) as $cur_id) {
            $symbol = Utils_CurrencyFieldCommon::get_symbol($cur_id);
            $symbol_pos_before = Utils_CurrencyFieldCommon::get_symbol_position($cur_id);
            // check for symbol
            if ($symbol_pos_before) {
                if (strpos($string, $symbol) === 0) {
                    $string = substr($string, strlen($symbol));
                } else continue;
            } else {
                $pos_of_sym = strlen($string) - strlen($symbol);
                if (strrpos($string, $symbol) == $pos_of_sym) {
                    $string = substr($string, 0, $pos_of_sym);
                } else continue;
            }
            // separate by decimal point
            $exp = explode(Utils_CurrencyFieldCommon::get_decimal_point($cur_id), $string);
            if (count($exp) > 2)
                continue;
            $fraction = count($exp) == 2 ? $exp[1] : '0';
            $int = $exp[0];
            if (!preg_match('/^\d+$/', $fraction))
                continue;
            $th_point = Utils_CurrencyFieldCommon::get_thousand_point($cur_id);
            if (strlen($th_point)) {
                $thparts = explode($th_point, $int);
                if (count($thparts) > 1) {
                    for ($i = 1; $i < count($thparts); $i++)
                        if (strlen($thparts[$i]) != 3)
                            continue 2;
                }
                $int = str_replace($th_point, '', $int);
            }
            if (preg_match('/^\-?\d+$/', $int)) {
                return array($int . '.' . $fraction, $cur_id);
            }
        }
        return null;
    }

    private static $cache;
    public static function load_cache() {
        if(!isset(self::$cache))
            self::$cache = DB::GetAssoc('SELECT id,pos_before,symbol,decimals,code,thousand_sign,decimal_sign FROM utils_currency');
    }
    public static function load_js() {
        self::load_cache();
        load_js('modules/Utils/CurrencyField/currency.js');
//        load_js('modules/Utils/CurrencyField/js/settings.js');
        $currencies = Utils_CurrencyFieldCommon::get_all_currencies();
        $js = 'Utils_CurrencyField.currencies=new Array();';
        foreach ($currencies as $k => $v) {
            $symbol = Utils_CurrencyFieldCommon::get_symbol($k);
            $position = Utils_CurrencyFieldCommon::get_symbol_position($k);
            $curr_format = '-?([0-9]*)\\'.Utils_CurrencyFieldCommon::get_decimal_point($k).'?[0-9]{0,'.Utils_CurrencyFieldCommon::get_precission($k).'}';
            $js .= 'Utils_CurrencyField.currencies[' . $k . ']={' . '"decp":"' . Utils_CurrencyFieldCommon::get_decimal_point($k) . '",' . '"thop":"' . Utils_CurrencyFieldCommon::get_thousand_point($k) . '",' . '"symbol_before":"' . ($position ? $symbol : '') . '",' . '"symbol_after":"' . (!$position ? $symbol : '') . '",' . '"dec_digits":' . Utils_CurrencyFieldCommon::get_precission($k) . ',' . 
                '"regex":'.json_encode($curr_format).'};';
        }
        eval_js_once($js);
    }

    public static function get_all_cryptocurrencies(){
        return DB::GetAssoc('SELECT id, code FROM utils_cryptocurrencies');
    }

    public static function get_cryptocurrencies(){
        return DB::GetAssoc('SELECT id, code FROM utils_cryptocurrencies WHERE active=1');
    }

    public static function get_cryptocurrency_by_id($id) {
        return DB::GetRow('SELECT code FROM utils_cryptocurrencies WHERE id=%d', [$id])['code'];
    }

    public static function cron() {
        if(Variable::get('curr_subscribe' === true)) {
            return [
                'cron_update_cryptocurrencies_rates' => 1,
                'cron_update_exchange_rates' => 1
            ];
        } else {
            return [];
        }
    }

    public static function cron_update_cryptocurrencies_rates () {

        $cryptos = self::get_all_cryptocurrencies();
        $fiats = Utils_CurrencyFieldCommon::get_all_currencies();
        $ticker = Utils_CurrencyFieldInstall::get_ticker_cryptocurrencies($cryptos, $fiats);

        $missed = ['crypto' => [], 'fiats' => []];
        foreach($fiats as $k => $v) {
            if(!key_exists($v, $ticker[$cryptos[1]])) {
                $missed['crypto'][] = $v;
            }
        }

        foreach($cryptos as $k => $v) {
            if(!key_exists($v, $ticker)) {
                $missed['fiats'][] = $v;
            }
        }

        if(empty($missed['crypto']) && empty($missed['fiats'])) {
            foreach($ticker as $k => $v) {
                Cache::init();
                Cache::set('crypto_'.$v, $ticker[$v]);
            }
        } else {
            if(empty($missed['fiats'])) {

            }
        }
        return $ticker;
    }

    public static function cron_update_exchange_rates () {

    }




//    public static function cron_exchange_rates () {
//        $d_curr = self::get_default_currency()['id'];
//        $d_crypto = self::get_default_cryptocurrency()['id'];
//        todo: cryptocompare obrazki
//    }
}

$GLOBALS['HTML_QUICKFORM_ELEMENT_TYPES']['currency'] = array('modules/Utils/CurrencyField/currency.php','HTML_QuickForm_currency');
on_init(array('Utils_CurrencyFieldCommon','load_js'));
?>
