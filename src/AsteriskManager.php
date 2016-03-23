<?php

namespace gietos;

/**
 * PHP Asterisk Manager functions
 * fork from https://github.com/d4rkstar/phpagi
 *
 * Authors: Matthew Asham <matthew@ochrelabs.com>, David Eder <david@eder.us> and others
 */
class AsteriskManager
{
    /**
     * @var string
     */
    public $server;
    /**
     * @var int
     */
    public $port = 5038;
    /**
     * @var string
     */
    public $username;
    /**
     * @var string
     */
    public $secret;
    /**
     * @var resource
     */
    protected $socket;

    /**
     * @var array Event Handlers
     */
    protected $eventHandlers;

    protected $buffer;

    /**
     * Whether we're successfully logged in
     *
     * @access private
     * @var boolean
     */
    protected $loggedIn = false;

    /**
     * Constructor
     *
     * @param array $config is an array of configuration values
     */
    public function __construct(array $config = [])
    {
        foreach ($config as $key => $value) {
            $this->$key = $value;
        }
    }

    /**
     * Sends a request.
     *
     * @param string $action
     * @param array  $parameters
     * @return array of parameters
     */
    protected function sendRequest($action, array $parameters = [])
    {
        $req = "Action: $action\r\n";
        $actionId = null;
        foreach ($parameters as $var => $val) {
            if (is_array($val)) {
                foreach ($val as $line) {
                    $req .= "$var: $line\r\n";
                }
            } else {
                $req .= "$var: $val\r\n";
                if (strtolower($var) === 'actionid') {
                    $actionId = $val;
                }
            }
        }
        if (!$actionId) {
            $actionId = $this->generateActionID();
            $req .= "ActionID: $actionId\r\n";
        }
        $req .= "\r\n";

        fwrite($this->socket, $req);

        return $this->waitResponse($actionId);
    }

    protected function readOneMsg()
    {
        $type = null;

        do {
            $buf = fgets($this->socket, 4096);
            if (false === $buf) {
                throw new \RuntimeException('Error reading from AMI socket');
            }
            $this->buffer .= $buf;

            $pos = strpos($this->buffer, "\r\n\r\n");
            if (false !== $pos) {
                // there's a full message in the buffer
                break;
            }
        } while (!feof($this->socket));

        $msg = substr($this->buffer, 0, $pos);
        $this->buffer = substr($this->buffer, $pos + 4);

        $msgarr = explode("\r\n", $msg);

        $parameters = [];

        $r = explode(': ', $msgarr[0]);
        $type = strtolower($r[0]);

        if ($r[1] === 'Follows') {
            $str = array_pop($msgarr);
            $lastline = strpos($str, '--END COMMAND--');
            if (false !== $lastline) {
                $parameters['data'] = substr($str, 0, $lastline - 1); // cut '\n' too
            }
        }

        foreach ($msgarr as $num => $str) {
            $kv = explode(':', $str, 2);
            if (!array_key_exists(1, $kv)) {
                $kv[1] = '';
            }
            $key = trim($kv[0]);
            $val = trim($kv[1]);
            $parameters[$key] = $val;
        }

        // process response
        switch ($type) {
            case '': // timeout occured
                break;
            case 'event':
                $this->processEvent($parameters);
                break;
            case 'response':
                break;
            default:
                // Unhandled response packet from Manager
                break;
        }

        return $parameters;
    }

    /**
     * Wait for a response
     *
     * If a request was just sent, this will return the response.
     * Otherwise, it will loop forever, handling events.
     *
     * XXX this code is slightly better then the original one
     * however it's still totally screwed up and needs to be rewritten,
     * for two reasons at least:
     * 1. it does not handle socket errors in any way
     * 2. it is terribly synchronous, esp. with eventlists,
     *    i.e. your code is blocked on waiting until full responce is received
     *
     * @param null $actionId
     *
     * @return array of parameters, empty on timeout
     * @internal param bool $allow_timeout if the socket times out, return an empty array
     */
    protected function waitResponse($actionId = null)
    {
        if ($actionId) {
            do {
                $res = $this->readOneMsg();
            } while (!(array_key_exists('ActionID', $res) && $res['ActionID'] === $actionId));
        } else {
            $res = $this->readOneMsg();
            return $res;
        }

        if (array_key_exists('EventList', $res) && $res['EventList'] === 'start') {
            $evlist = [];
            do {
                $res = $this->waitResponse($actionId);
                if (array_key_exists('EventList', $res) && $res['EventList'] === 'Complete') {
                    break;
                } else {
                    $evlist[] = $res;
                }
            } while (true);
            $res['events'] = $evlist;
        }

        return $res;
    }

    /**
     * Connect to Asterisk
     *
     * @return bool true on success
     * @throws \RuntimeException
     */
    public function connect()
    {
        // connect the socket
        $errno = $errstr = null;
        $this->socket = @fsockopen($this->server, $this->port, $errno, $errstr);
        if (false === $this->socket) {
            throw new \RuntimeException("Unable to connect to manager {$this->server}:{$this->port} ($errno): $errstr");
        }

        // read the header
        $str = fgets($this->socket);
        if (false === $str) {
            throw new \RuntimeException('Asterisk Manager header was not received.');
        }

        // login
        $res = $this->sendRequest('login', ['Username' => $this->username, 'Secret' => $this->secret]);
        if ($res['Response'] !== 'Success') {
            $this->disconnect();
            throw new \RuntimeException('Login failed');
        }
        $this->loggedIn = true;
        return true;
    }

    /**
     * Disconnect
     */
    public function disconnect()
    {
        if ($this->loggedIn === true) {
            $this->logoff();
        }
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    /**
     * Set Absolute Timeout
     *
     * Hangup a channel after a certain time.
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+AbsoluteTimeout
     *
     * @param string  $channel Channel name to hangup
     * @param integer $timeout Maximum duration of the call (sec)
     *
     * @return array
     */
    public function absoluteTimeout($channel, $timeout)
    {
        return $this->sendRequest('AbsoluteTimeout', ['Channel' => $channel, 'Timeout' => $timeout]);
    }

    /**
     * Change monitoring filename of a channel
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ChangeMonitor
     *
     * @param string $channel the channel to record.
     * @param string $file    the new name of the file created in the monitor spool directory.
     *
     * @return array
     */
    public function changeMonitor($channel, $file)
    {
        return $this->sendRequest('ChangeMontior', ['Channel' => $channel, 'File' => $file]);
    }

    /**
     * Execute Command
     *
     * @example examples/sip_show_peer.php Get information about a sip peer
     * @link    http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Command
     * @link    http://www.voip-info.org/wiki-Asterisk+CLI
     *
     * @param string $command
     * @param string $actionId message matching variable
     *
     * @return array
     */
    public function command($command, $actionId = null)
    {
        $parameters = ['Command' => $command];
        if ($actionId) {
            $parameters['ActionID'] = $actionId;
        }
        return $this->sendRequest('Command', $parameters);
    }

    /**
     * Enable/Disable sending of events to this manager
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Events
     *
     * @param string $eventmask is either 'on', 'off', or 'system,call,log'
     *
     * @return array
     */
    public function events($eventmask)
    {
        return $this->sendRequest('Events', ['EventMask' => $eventmask]);
    }

    /**
     *  Generate random ActionID
     **/
    protected function generateActionID()
    {
        return 'A' . sprintf(mt_rand(), '%6d');
    }

    /**
     * DBGet
     * http://www.voip-info.org/wiki/index.php?page=Asterisk+Manager+API+Action+DBGet
     *
     * @param string      $family key family
     * @param string      $key    key name
     * @param string|null $actionId
     *
     * @return mixed|string
     */
    public function dBGet($family, $key, $actionId = null)
    {
        $parameters = ['Family' => $family, 'Key' => $key];
        if (null === $actionId) {
            $actionId = $this->generateActionID();
        }

        $parameters['ActionID'] = $actionId;
        $response = $this->sendRequest('DBGet', $parameters);
        if ($response['Response'] === 'Success') {
            $response = $this->waitResponse($actionId);
            return $response['Val'];
        }

        return '';
    }

    /**
     * Check Extension Status
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ExtensionState
     *
     * @param string $exten    Extension to check state on
     * @param string $context  Context for extension
     * @param string $actionId message matching variable
     *
     * @return array
     */
    public function extensionState($exten, $context, $actionId = null)
    {
        $parameters = ['Exten' => $exten, 'Context' => $context];
        if ($actionId) {
            $parameters['ActionID'] = $actionId;
        }

        return $this->sendRequest('ExtensionState', $parameters);
    }

    /**
     * Gets a Channel Variable
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+GetVar
     * @link http://www.voip-info.org/wiki-Asterisk+variables
     *
     * @param string $channel  Channel to read variable from
     * @param string $variable
     * @param string $actionId message matching variable
     *
     * @return array
     */
    public function getVar($channel, $variable, $actionId = null)
    {
        $parameters = ['Channel' => $channel, 'Variable' => $variable];
        if ($actionId) {
            $parameters['ActionID'] = $actionId;
        }
        return $this->sendRequest('GetVar', $parameters);
    }

    /**
     * Hangup Channel
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Hangup
     *
     * @param string $channel The channel name to be hungup
     *
     * @return array
     */
    public function hangup($channel)
    {
        return $this->sendRequest('Hangup', ['Channel' => $channel]);
    }

    /**
     * List IAX Peers
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+IAXpeers
     */
    public function iAXPeers()
    {
        return $this->sendRequest('IAXPeers');
    }

    /**
     * List available manager commands
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ListCommands
     *
     * @param string $actionId message matching variable
     *
     * @return array
     */
    public function listCommands($actionId = null)
    {
        if ($actionId) {
            return $this->sendRequest('ListCommands', ['ActionID' => $actionId]);
        }

        return $this->sendRequest('ListCommands');
    }

    /**
     * Logoff Manager
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Logoff
     */
    protected function logoff()
    {
        return $this->sendRequest('Logoff');
    }

    /**
     * Check Mailbox Message Count
     *
     * Returns number of new and old messages.
     *   Message: Mailbox Message Count
     *   Mailbox: <mailboxid>
     *   NewMessages: <count>
     *   OldMessages: <count>
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+MailboxCount
     *
     * @param string $mailbox  Full mailbox ID <mailbox>@<vm-context>
     * @param string $actionId message matching variable
     *
     * @return array
     */
    public function mailboxCount($mailbox, $actionId = null)
    {
        $parameters = ['Mailbox' => $mailbox];
        if ($actionId) {
            $parameters['ActionID'] = $actionId;
        }
        return $this->sendRequest('MailboxCount', $parameters);
    }

    /**
     * Check Mailbox
     *
     * Returns number of messages.
     *   Message: Mailbox Status
     *   Mailbox: <mailboxid>
     *   Waiting: <count>
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+MailboxStatus
     *
     * @param string $mailbox  Full mailbox ID <mailbox>@<vm-context>
     * @param string $actionId message matching variable
     *
     * @return array
     */
    public function mailboxStatus($mailbox, $actionId = null)
    {
        $parameters = ['Mailbox' => $mailbox];
        if ($actionId) {
            $parameters['ActionID'] = $actionId;
        }
        return $this->sendRequest('MailboxStatus', $parameters);
    }

    /**
     * Monitor a channel
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Monitor
     *
     * @param string  $channel
     * @param string  $file
     * @param string  $format
     * @param boolean $mix
     *
     * @return array
     */
    public function monitor($channel, $file = null, $format = null, $mix = null)
    {
        $parameters = ['Channel' => $channel];
        if ($file) {
            $parameters['File'] = $file;
        }
        if ($format) {
            $parameters['Format'] = $format;
        }
        if (null !== $file) {
            $parameters['Mix'] = $mix ? 'true' : 'false';
        }
        return $this->sendRequest('Monitor', $parameters);
    }

    /**
     * Originates Call.
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Originate
     *
     * @param string  $channel     Channel name to call
     * @param string  $exten       Extension to use (requires 'Context' and 'Priority')
     * @param string  $context     Context to use (requires 'Exten' and 'Priority')
     * @param string  $priority    Priority to use (requires 'Exten' and 'Context')
     * @param string  $application Application to use
     * @param string  $data        Data to use (requires 'Application')
     * @param integer $timeout     How long to wait for call to be answered (in ms)
     * @param string  $callerid    Caller ID to be set on the outgoing channel
     * @param string  $variable    Channel variable to set (VAR1=value1|VAR2=value2)
     * @param string  $account     Account code
     * @param boolean $async       true fast origination
     * @param string  $actionid    message matching variable
     *
     * @return array
     */
    public function originate(
        $channel,
        $exten = null,
        $context = null,
        $priority = null,
        $application = null,
        $data = null,
        $timeout = null,
        $callerid = null,
        $variable = null,
        $account = null,
        $async = null,
        $actionid = null
    )
    {
        $parameters = ['Channel' => $channel];

        if ($exten) {
            $parameters['Exten'] = $exten;
        }
        if ($context) {
            $parameters['Context'] = $context;
        }
        if ($priority) {
            $parameters['Priority'] = $priority;
        }
        if ($application) {
            $parameters['Application'] = $application;
        }
        if ($data) {
            $parameters['Data'] = $data;
        }
        if ($timeout) {
            $parameters['Timeout'] = $timeout;
        }
        if ($callerid) {
            $parameters['CallerID'] = $callerid;
        }
        if ($variable) {
            $parameters['Variable'] = $variable;
        }
        if ($account) {
            $parameters['Account'] = $account;
        }
        if (null !== $async) {
            $parameters['Async'] = $async ? 'true' : 'false';
        }
        if ($actionid) {
            $parameters['ActionID'] = $actionid;
        } 

        return $this->sendRequest('Originate', $parameters);
    }

    /**
     * List parked calls
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ParkedCalls
     *
     * @param string $actionId message matching variable
     *
     * @return array
     */
    public function parkedCalls($actionId = null)
    {
        if ($actionId) {
            return $this->sendRequest('ParkedCalls', ['ActionID' => $actionId]);
        }

        return $this->sendRequest('ParkedCalls');
    }

    /**
     * Ping
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Ping
     */
    public function ping()
    {
        return $this->sendRequest('Ping');
    }

    /**
     * Queue Add
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+QueueAdd
     *
     * @param string  $queue
     * @param string  $interface
     * @param integer $penalty
     * @param string  $memberName
     *
     * @return array
     */
    public function queueAdd($queue, $interface, $penalty = 0, $memberName = null)
    {
        $parameters = ['Queue' => $queue, 'Interface' => $interface];
        if ($penalty) {
            $parameters['Penalty'] = $penalty;
        }
        if ($memberName) {
            $parameters['MemberName'] = $memberName;
        }
        return $this->sendRequest('QueueAdd', $parameters);
    }

    /**
     * Queue Remove
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+QueueRemove
     *
     * @param string $queue
     * @param string $interface
     *
     * @return array
     */
    public function queueRemove($queue, $interface)
    {
        return $this->sendRequest('QueueRemove', ['Queue' => $queue, 'Interface' => $interface]);
    }

    /**
     * Queues
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Queues
     */
    public function queues()
    {
        return $this->sendRequest('Queues');
    }

    /**
     * Queue Status
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+QueueStatus
     *
     * @param string $actionId message matching variable
     *
     * @return array
     */
    public function queueStatus($actionId = null)
    {
        if ($actionId) {
            return $this->sendRequest('QueueStatus', ['ActionID' => $actionId]);
        }

        return $this->sendRequest('QueueStatus');
    }

    /**
     * Redirect
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Redirect
     *
     * @param string $channel
     * @param string $extrachannel
     * @param string $exten
     * @param string $context
     * @param string $priority
     *
     * @return array
     */
    public function redirect($channel, $extrachannel, $exten, $context, $priority)
    {
        return $this->sendRequest('Redirect', [
            'Channel' => $channel,
            'ExtraChannel' => $extrachannel,
            'Exten' => $exten,
            'Context' => $context,
            'Priority' => $priority
        ]);
    }

    /**
     * Set the CDR UserField
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+SetCDRUserField
     *
     * @param string $userfield
     * @param string $channel
     * @param string $append
     *
     * @return array
     */
    public function setCDRUserField($userfield, $channel, $append = null)
    {
        $parameters = ['UserField' => $userfield, 'Channel' => $channel];
        if ($append) {
            $parameters['Append'] = $append;
        }
        return $this->sendRequest('SetCDRUserField', $parameters);
    }

    /**
     * Set Channel Variable
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+SetVar
     *
     * @param string $channel  Channel to set variable for
     * @param string $variable name
     * @param string $value
     *
     * @return array
     */
    public function setVar($channel, $variable, $value)
    {
        return $this->sendRequest('SetVar', ['Channel' => $channel, 'Variable' => $variable, 'Value' => $value]);
    }

    /**
     * Channel Status
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Status
     *
     * @param  string $channel
     * @param string  $actionId message matching variable
     *
     * @return array
     */
    public function status($channel, $actionId = null)
    {
        $parameters = ['Channel' => $channel];
        if ($actionId) {
            $parameters['ActionID'] = $actionId;
        }
        return $this->sendRequest('Status', $parameters);
    }

    /**
     * Stop monitoring a channel
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+StopMonitor
     *
     * @param string $channel
     *
     * @return array
     */
    public function stopMonitor($channel)
    {
        return $this->sendRequest('StopMonitor', ['Channel' => $channel]);
    }

    /**
     * Dial over Zap channel while offhook
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapDialOffhook
     *
     * @param string $zapchannel
     * @param string $number
     *
     * @return array
     */
    public function zapDialOffhook($zapchannel, $number)
    {
        return $this->sendRequest('ZapDialOffhook', ['ZapChannel' => $zapchannel, 'Number' => $number]);
    }

    /**
     * Toggle Zap channel Do Not Disturb status OFF
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapDNDoff
     *
     * @param string $zapchannel
     *
     * @return array
     */
    public function zapDNDoff($zapchannel)
    {
        return $this->sendRequest('ZapDNDoff', ['ZapChannel' => $zapchannel]);
    }

    /**
     * Toggle Zap channel Do Not Disturb status ON
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapDNDon
     *
     * @param string $zapchannel
     *
     * @return array
     */
    public function zapDNDon($zapchannel)
    {
        return $this->sendRequest('ZapDNDon', ['ZapChannel' => $zapchannel]);
    }

    /**
     * Hangup Zap Channel
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapHangup
     *
     * @param string $zapchannel
     *
     * @return array
     */
    public function zapHangup($zapchannel)
    {
        return $this->sendRequest('ZapHangup', ['ZapChannel' => $zapchannel]);
    }

    /**
     * Transfer Zap Channel
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapTransfer
     *
     * @param string $zapchannel
     *
     * @return array
     */
    public function zapTransfer($zapchannel)
    {
        return $this->sendRequest('ZapTransfer', ['ZapChannel' => $zapchannel]);
    }

    /**
     * Zap Show Channels
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapShowChannels
     *
     * @param string $actionid message matching variable
     *
     * @return array
     */
    public function zapShowChannels($actionid = null)
    {
        if ($actionid) {
            return $this->sendRequest('ZapShowChannels', ['ActionID' => $actionid]);
        }

        return $this->sendRequest('ZapShowChannels');
    }

    /**
     * Log a message
     *
     * @deprecated
     * @param string $message
     * @param integer $level from 1 to 4
     */
    protected function log($message, $level = 1)
    {
    }

    /**
     * Add event handler
     *
     * Known Events include ( http://www.voip-info.org/wiki-asterisk+manager+events )
     *   Link - Fired when two voice channels are linked together and voice data exchange commences.
     *   Unlink - Fired when a link between two voice channels is discontinued, for example, just before call
     *   completion. Newexten - Hangup - Newchannel - Newstate - Reload - Fired when the "RELOAD" console command is
     *   executed. Shutdown - ExtensionStatus - Rename - Newcallerid - Alarm - AlarmClear - Agentcallbacklogoff -
     *   Agentcallbacklogin - Agentlogoff - MeetmeJoin - MessageWaiting - join - leave - AgentCalled - ParkedCall -
     *   Fired after ParkedCalls Cdr - ParkedCallsComplete - QueueParams - QueueMember - QueueStatusEnd - Status -
     *   StatusComplete - ZapShowChannels - Fired after ZapShowChannels ZapShowChannelsComplete -
     *
     * @param string $event    type or * for default handler
     * @param string $callback function
     * @return boolean sucess
     */
    public function addEventHandler($event, $callback)
    {
        $event = strtolower($event);
        if (array_key_exists($event, $this->eventHandlers)) {
            return false;
        }
        $this->eventHandlers[$event] = $callback;
        return true;
    }

    /**
     * Removes event handler.
     *
     * @param string $event type or * for default handler
     * @return boolean sucess
     **/
    public function removeEventHandler($event)
    {
        $event = strtolower($event);
        if (array_key_exists($event, $this->eventHandlers)) {
            unset($this->eventHandlers[$event]);
            return true;
        }

        return false;
    }

    /**
     * Process event
     *
     * @param array $parameters
     * @return mixed result of event handler or false if no handler was found
     */
    protected function processEvent($parameters)
    {
        $ret = false;
        $e = strtolower($parameters['Event']);

        $handler = '';
        if (array_key_exists($e, $this->eventHandlers)) {
            $handler = $this->eventHandlers[$e];
        } elseif (array_key_exists('*', $this->eventHandlers)) {
            $handler = $this->eventHandlers['*'];
        }

        if (function_exists($handler)) {
            $ret = $handler($e, $parameters, $this->server, $this->port);
        } elseif (is_array($handler)) {
            $ret = call_user_func($handler, $e, $parameters, $this->server, $this->port);
        }
        
        return $ret;
    }
}
