<?php

namespace App\Services\Snmp;

/* SnmpClient on top of the php-snmp extension, SNMP v2c or v3 */
class PhpSnmpClient implements SnmpClient
{
    /* What php-snmp accepts: PHP 7.4 knows MD5 and SHA, PHP 8.1 adds SHA-2; privacy is DES or
       AES-128 everywhere (no PHP version speaks AES-192/256) */
    const PRIV_PROTOCOLS = ['DES', 'AES'];

    public static function authProtocols()
    {
        return PHP_VERSION_ID >= 80100 ? ['MD5', 'SHA', 'SHA224', 'SHA256', 'SHA384', 'SHA512'] : ['MD5', 'SHA'];
    }

    protected $config, $contextName;

    /**
     * @param array $config host, version ("2c" or "3"), community (v2c), user, auth_protocol,
     *                      auth_password, priv_protocol, priv_password (v3), timeout (microseconds), retries
     */
    public function __construct(array $config)
    {
        if (!class_exists(\SNMP::class))
            throw new SnmpError('The PHP SNMP extension is missing: sudo apt install php-snmp');

        $this->config = $config + [
            'version' => '2c', 'community' => 'public', 'user' => '', 'auth_protocol' => '', 'auth_password' => '',
            'priv_protocol' => '', 'priv_password' => '', 'timeout' => 1000000, 'retries' => 1,
        ];
    }

    public function host()
    {
        return $this->config['host'];
    }

    public function walk($oid, $vlan = null)
    {
        $session = $this->session($vlan);
        $result = @$session->walk($oid);
        $errno = $session->getErrno();
        $error = $session->getError();
        $session->close();

        if ($result === false || ($errno != \SNMP::ERRNO_NOERROR && !$result))
        {
            if ($errno == \SNMP::ERRNO_TIMEOUT)
                throw new SnmpError($error ?: 'No response from '.$this->config['host'], true);
            if ($errno == \SNMP::ERRNO_NOERROR || preg_match('/No Such (Object|Instance)|No more variables|End of MIB/i', $error))
                return [];
            throw new SnmpError($error ?: 'SNMP error '.$errno);
        }

        $out = [];
        foreach ($result as $key => $value)
            $out[$key[0] === '.' ? $key : '.'.$key] = $value;
        return $out;
    }

    protected function session($vlan)
    {
        $c = $this->config;
        if ($c['version'] === '3')
        {
            $session = new \SNMP(\SNMP::VERSION_3, $c['host'], $c['user'], $c['timeout'], $c['retries']);
            $auth = $c['auth_protocol'] && $c['auth_password'] !== '';
            $priv = $auth && $c['priv_protocol'] && $c['priv_password'] !== '';
            $level = $priv ? 'authPriv' : ($auth ? 'authNoPriv' : 'noAuthNoPriv');
            // php-snmp keeps a pointer to the context name instead of copying it, so the string has to
            // outlive the session: a freed one turns into garbage and the switch never answers
            $this->contextName = $vlan !== null ? 'vlan-'.$vlan : '';
            $ok = @$session->setSecurity($level, $auth ? $c['auth_protocol'] : '', $auth ? $c['auth_password'] : '',
                                         $priv ? $c['priv_protocol'] : '', $priv ? $c['priv_password'] : '',
                                         $this->contextName, '');
            if (!$ok)
                throw new SnmpError('SNMPv3 settings refused: '.($session->getError() ?: 'unsupported protocol?'));
        }
        else
        {
            $community = $c['community'].($vlan !== null ? '@'.$vlan : '');
            $session = new \SNMP(\SNMP::VERSION_2c, $c['host'], $community, $c['timeout'], $c['retries']);
        }

        $session->oid_output_format = SNMP_OID_OUTPUT_NUMERIC;
        $session->valueretrieval = SNMP_VALUE_PLAIN;
        $session->quick_print = true;
        $session->enum_print = true;
        return $session;
    }
}
