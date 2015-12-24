<?php

namespace gietos\AGI;

/**
 * PHP AGI Functions for Asterisk
 * fork from https://github.com/d4rkstar/phpagi
 *
 * Authors: Matthew Asham <matthew@ochrelabs.com>, David Eder <david@eder.us> and others
 */
class Handler
{
    const AGIRES_OK = 200;
    const AST_STATE_DOWN = 0;
    const AST_STATE_RESERVED = 1;
    const AST_STATE_OFFHOOK = 2;
    const AST_STATE_DIALING = 3;
    const AST_STATE_RING = 4;
    const AST_STATE_RINGING = 5;
    const AST_STATE_UP = 6;
    const AST_STATE_BUSY = 7;
    const AST_STATE_DIALING_OFFHOOK = 8;
    const AST_STATE_PRERING = 9;

    /**
     * @var Request
     */
    public $request;
    /**
     * @var resource Input Stream
     */
    protected $in;
    /**
     * Output Stream
     */
    protected $out;

    /**
     * @var string Application option delimiter
     */
    public $option_delim = ',';

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

        ob_implicit_flush(true);

        // open stdin & stdout
        if (!isset($this->in)) {
            $this->in = defined('STDIN') ? STDIN : fopen('php://stdin', 'r');
        }

        $this->out = defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w');
    }

    public function init()
    {
        $rawRequest = $str = fgets($this->in);
        while ($str != "\n") {
            $rawRequest .= $str = fgets($this->in);
        }

        $this->request = RequestParser::createRequest($rawRequest);
    }

    /**
     * Answer channel if not already in answer state.
     *
     * @link    http://www.voip-info.org/wiki-answer
     * @example examples/dtmf.php Get DTMF tones from the user and say the digits
     * @example examples/input.php Get text input from the user and say it back
     * @example examples/ping.php Ping an IP address
     *
     * @return array, see evaluate for return information.  ['result'] is 0 on success, -1 on failure.
     */
    public function answer()
    {
        return $this->evaluate('ANSWER');
    }

    /**
     * Get the status of the specified channel. If no channel name is specified, return the status of the current
     * channel.
     *
     * @link http://www.voip-info.org/wiki-channel+status
     * @param string $channel
     * @return array, see evaluate for return information. ['data'] contains description.
     */
    public function channel_status($channel = '')
    {
        $ret = $this->evaluate("CHANNEL STATUS $channel");
        switch ($ret['result']) {
            case -1:
                $ret['data'] = trim("There is no channel that matches $channel");
                break;
            case self::AST_STATE_DOWN:
                $ret['data'] = 'Channel is down and available';
                break;
            case self::AST_STATE_RESERVED:
                $ret['data'] = 'Channel is down, but reserved';
                break;
            case self::AST_STATE_OFFHOOK:
                $ret['data'] = 'Channel is off hook';
                break;
            case self::AST_STATE_DIALING:
                $ret['data'] = 'Digits (or equivalent) have been dialed';
                break;
            case self::AST_STATE_RING:
                $ret['data'] = 'Line is ringing';
                break;
            case self::AST_STATE_RINGING:
                $ret['data'] = 'Remote end is ringing';
                break;
            case self::AST_STATE_UP:
                $ret['data'] = 'Line is up';
                break;
            case self::AST_STATE_BUSY:
                $ret['data'] = 'Line is busy';
                break;
            case self::AST_STATE_DIALING_OFFHOOK:
                $ret['data'] = 'Digits (or equivalent) have been dialed while offhook';
                break;
            case self::AST_STATE_PRERING:
                $ret['data'] = 'Channel has detected an incoming call and is waiting for ring';
                break;
            default:
                $ret['data'] = "Unknown ({$ret['result']})";
                break;
        }
        return $ret;
    }

    /**
     * Deletes an entry in the Asterisk database for a given family and key.
     *
     * @link http://www.voip-info.org/wiki-database+del
     * @param string $family
     * @param string $key
     * @return array, see evaluate for return information. ['result'] is 1 on sucess, 0 otherwise.
     */
    public function database_del($family, $key)
    {
        return $this->evaluate("DATABASE DEL \"$family\" \"$key\"");
    }

    /**
     * Deletes a family or specific keytree within a family in the Asterisk database.
     *
     * @link http://www.voip-info.org/wiki-database+deltree
     * @param string $family
     * @param string $keytree
     * @return array, see evaluate for return information. ['result'] is 1 on sucess, 0 otherwise.
     */
    public function database_deltree($family, $keytree = '')
    {
        $cmd = "DATABASE DELTREE \"$family\"";
        if ($keytree != '') $cmd .= " \"$keytree\"";
        return $this->evaluate($cmd);
    }

    /**
     * Retrieves an entry in the Asterisk database for a given family and key.
     *
     * @link http://www.voip-info.org/wiki-database+get
     * @param string $family
     * @param string $key
     * @return array, see evaluate for return information. ['result'] is 1 on sucess, 0 failure. ['data'] holds the
     *                value
     */
    public function database_get($family, $key)
    {
        return $this->evaluate("DATABASE GET \"$family\" \"$key\"");
    }

    /**
     * Adds or updates an entry in the Asterisk database for a given family, key, and value.
     *
     * @param string $family
     * @param string $key
     * @param string $value
     * @return array, see evaluate for return information. ['result'] is 1 on sucess, 0 otherwise
     */
    public function database_put($family, $key, $value)
    {
        $value = str_replace("\n", '\n', addslashes($value));
        return $this->evaluate("DATABASE PUT \"$family\" \"$key\" \"$value\"");
    }

    /**
     * Sets a global variable, using Asterisk 1.6 syntax.
     *
     * @link http://www.voip-info.org/wiki/view/Asterisk+cmd+Set
     *
     * @param string           $pVariable
     * @param string|int|float $pValue
     * @return array, see evaluate for return information. ['result'] is 1 on sucess, 0 otherwise
     */
    public function set_global_var($pVariable, $pValue)
    {
        if (is_numeric($pValue))
            return $this->evaluate("Set({$pVariable}={$pValue},g);");
        else
            return $this->evaluate("Set({$pVariable}=\"{$pValue}\",g);");
    }

    /**
     * Sets a variable, using Asterisk 1.6 syntax.
     *
     * @link http://www.voip-info.org/wiki/view/Asterisk+cmd+Set
     *
     * @param string           $pVariable
     * @param string|int|float $pValue
     * @return array, see evaluate for return information. ['result'] is 1 on sucess, 0 otherwise
     */
    public function set_var($pVariable, $pValue)
    {
        if (is_numeric($pValue))
            return $this->evaluate("Set({$pVariable}={$pValue});");
        else
            return $this->evaluate("Set({$pVariable}=\"{$pValue}\");");
    }

    /**
     * Executes the specified Asterisk application with given options.
     *
     * @link http://www.voip-info.org/wiki-exec
     * @link http://www.voip-info.org/wiki-Asterisk+-+documentation+of+application+commands
     * @param string $application
     * @param mixed  $options
     * @return array, see evaluate for return information. ['result'] is whatever the application returns, or -2 on
     *                failure to find application
     */
    public function exec($application, $options)
    {
        if (is_array($options)) {
            $options = join('|', $options);
        }

        return $this->evaluate("EXEC $application $options");
    }

    /**
     * Plays the given file and receives DTMF data.
     *
     * This is similar to STREAM FILE, but this command can accept and return many DTMF digits,
     * while STREAM FILE returns immediately after the first DTMF digit is detected.
     *
     * Asterisk looks for the file to play in /var/lib/asterisk/sounds by default.
     *
     * If the user doesn't press any keys when the message plays, there is $timeout milliseconds
     * of silence then the command ends.
     *
     * The user has the opportunity to press a key at any time during the message or the
     * post-message silence. If the user presses a key while the message is playing, the
     * message stops playing. When the first key is pressed a timer starts counting for
     * $timeout milliseconds. Every time the user presses another key the timer is restarted.
     * The command ends when the counter goes to zero or the maximum number of digits is entered,
     * whichever happens first.
     *
     * If you don't specify a time out then a default timeout of 2000 is used following a pressed
     * digit. If no digits are pressed then 6 seconds of silence follow the message.
     *
     * If you don't specify $max_digits then the user can enter as many digits as they want.
     *
     * Pressing the # key has the same effect as the timer running out: the command ends and
     * any previously keyed digits are returned. A side effect of this is that there is no
     * way to read a # key using this command.
     *
     * @example examples/ping.php Ping an IP address
     *
     * @link    http://www.voip-info.org/wiki-get+data
     * @param string  $filename file to play. Do not include file extension.
     * @param integer $timeout  milliseconds
     * @param integer $max_digits
     * @return array, see evaluate for return information. ['result'] holds the digits and ['data'] holds the timeout
     *                if present.
     *
     * This differs from other commands with return DTMF as numbers representing ASCII characters.
     */
    public function get_data($filename, $timeout = null, $max_digits = null)
    {
        return $this->evaluate(rtrim("GET DATA $filename $timeout $max_digits"));
    }

    /**
     * Fetch the value of a variable.
     *
     * Does not work with global variables. Does not work with some variables that are generated by modules.
     *
     * @link http://www.voip-info.org/wiki-get+variable
     * @link http://www.voip-info.org/wiki-Asterisk+variables
     * @param string  $variable name
     * @param boolean $getvalue return the value only
     * @return array, see evaluate for return information. ['result'] is 0 if variable hasn't been set, 1 if it has.
     *                ['data'] holds the value. returns value if $getvalue is TRUE
     */
    public function get_variable($variable, $getvalue = false)
    {
        $res = $this->evaluate("GET VARIABLE $variable");

        if ($getvalue == false)
            return ($res);

        return ($res['data']);
    }

    /**
     * Fetch the value of a full variable.
     *
     * @link http://www.voip-info.org/wiki/view/get+full+variable
     * @link http://www.voip-info.org/wiki-Asterisk+variables
     * @param string      $variable name
     * @param string|bool $channel  channel
     * @param bool        $getvalue return the value only
     * @return array, see evaluate for return information. ['result'] is 0 if variable hasn't been set, 1 if it has.
     *                              ['data'] holds the value.  returns value if $getvalue is TRUE
     */
    public function get_fullvariable($variable, $channel = false, $getvalue = false)
    {
        if ($channel == false) {
            $req = $variable;
        } else {
            $req = $variable . ' ' . $channel;
        }

        $res = $this->evaluate('GET FULL VARIABLE ' . $req);

        if ($getvalue == false)
            return ($res);

        return ($res['data']);

    }

    /**
     * Hangup the specified channel. If no channel name is given, hang up the current channel.
     *
     * With power comes responsibility. Hanging up channels other than your own isn't something
     * that is done routinely. If you are not sure why you are doing so, then don't.
     *
     * @link    http://www.voip-info.org/wiki-hangup
     * @example examples/dtmf.php Get DTMF tones from the user and say the digits
     * @example examples/input.php Get text input from the user and say it back
     * @example examples/ping.php Ping an IP address
     *
     * @param string $channel
     * @return array, see evaluate for return information. ['result'] is 1 on success, -1 on failure.
     */
    public function hangup($channel = '')
    {
        return $this->evaluate("HANGUP $channel");
    }

    /**
     * Does nothing.
     *
     * @link http://www.voip-info.org/wiki-noop
     * @param string $string
     * @return array, see evaluate for return information.
     */
    public function noop($string = '')
    {
        return $this->evaluate("NOOP \"$string\"");
    }

    /**
     * Receive a character of text from a connected channel. Waits up to $timeout milliseconds for
     * a character to arrive, or infinitely if $timeout is zero.
     *
     * @link http://www.voip-info.org/wiki-receive+char
     * @param integer $timeout milliseconds
     * @return array, see evaluate for return information. ['result'] is 0 on timeout or not supported, -1 on failure.
     *                Otherwise it is the decimal value of the DTMF tone. Use chr() to convert to ASCII.
     */
    public function receive_char($timeout = -1)
    {
        return $this->evaluate("RECEIVE CHAR $timeout");
    }

    /**
     * Record sound to a file until an acceptable DTMF digit is received or a specified amount of
     * time has passed. Optionally the file BEEP is played before recording begins.
     *
     * @link http://www.voip-info.org/wiki-record+file
     * @param string  $file    to record, without extension, often created in /var/lib/asterisk/sounds
     * @param string  $format  of the file. GSM and WAV are commonly used formats. MP3 is read-only and thus cannot be
     *                         used.
     * @param string  $escape_digits
     * @param integer $timeout is the maximum record time in milliseconds, or -1 for no timeout.
     * @param integer $offset  to seek to without exceeding the end of the file.
     * @param boolean $beep
     * @param integer $silence number of seconds of silence allowed before the function returns despite the
     *                         lack of dtmf digits or reaching timeout.
     * @return array, see evaluate for return information. ['result'] is -1 on error, 0 on hangup, otherwise a decimal
     *                value of the DTMF tone. Use chr() to convert to ASCII.
     */
    public function record_file($file, $format, $escape_digits = '', $timeout = -1, $offset = null, $beep = false, $silence = null)
    {
        $cmd = trim("RECORD FILE $file $format \"$escape_digits\" $timeout $offset");
        if ($beep) $cmd .= ' BEEP';
        if (!is_null($silence)) $cmd .= " s=$silence";
        return $this->evaluate($cmd);
    }

    /**
     * Say the given digit string, returning early if any of the given DTMF escape digits are received on the channel.
     *
     * @link http://www.voip-info.org/wiki-say+digits
     * @param integer $digits
     * @param string  $escape_digits
     * @return array, see evaluate for return information. ['result'] is -1 on hangup or error, 0 if playback completes
     *                with no digit received, otherwise a decimal value of the DTMF tone.  Use chr() to convert to
     *                ASCII.
     */
    public function say_digits($digits, $escape_digits = '')
    {
        return $this->evaluate("SAY DIGITS $digits \"$escape_digits\"");
    }

    /**
     * Say the given number, returning early if any of the given DTMF escape digits are received on the channel.
     *
     * @link http://www.voip-info.org/wiki-say+number
     * @param integer $number
     * @param string  $escape_digits
     * @return array, see evaluate for return information. ['result'] is -1 on hangup or error, 0 if playback completes
     *                with no digit received, otherwise a decimal value of the DTMF tone.  Use chr() to convert to
     *                ASCII.
     */
    public function say_number($number, $escape_digits = '')
    {
        return $this->evaluate("SAY NUMBER $number \"$escape_digits\"");
    }

    /**
     * Say the given character string, returning early if any of the given DTMF escape digits are received on the
     * channel.
     *
     * @link http://www.voip-info.org/wiki-say+phonetic
     * @param string $text
     * @param string $escape_digits
     * @return array, see evaluate for return information. ['result'] is -1 on hangup or error, 0 if playback completes
     *                with no digit received, otherwise a decimal value of the DTMF tone.  Use chr() to convert to
     *                ASCII.
     */
    public function say_phonetic($text, $escape_digits = '')
    {
        return $this->evaluate("SAY PHONETIC $text \"$escape_digits\"");
    }

    /**
     * Say a given time, returning early if any of the given DTMF escape digits are received on the channel.
     *
     * @link http://www.voip-info.org/wiki-say+time
     * @param integer $time number of seconds elapsed since 00:00:00 on January 1, 1970, Coordinated Universal Time
     *                      (UTC).
     * @param string  $escape_digits
     * @return array, see evaluate for return information. ['result'] is -1 on hangup or error, 0 if playback completes
     *                with no digit received, otherwise a decimal value of the DTMF tone.  Use chr() to convert to
     *                ASCII.
     */
    public function say_time($time = null, $escape_digits = '')
    {
        if (is_null($time)) $time = time();
        return $this->evaluate("SAY TIME $time \"$escape_digits\"");
    }

    /**
     * Send the specified image on a channel.
     *
     * Most channels do not support the transmission of images.
     *
     * @link http://www.voip-info.org/wiki-send+image
     * @param string $image without extension, often in /var/lib/asterisk/images
     * @return array, see evaluate for return information. ['result'] is -1 on hangup or error, 0 if the image is sent
     *                or channel does not support image transmission.
     */
    public function send_image($image)
    {
        return $this->evaluate("SEND IMAGE $image");
    }

    /**
     * Send the given text to the connected channel.
     *
     * Most channels do not support transmission of text.
     *
     * @link http://www.voip-info.org/wiki-send+text
     * @param $text
     * @return array, see evaluate for return information. ['result'] is -1 on hangup or error, 0 if the text is sent or
     * channel does not support text transmission.
     */
    public function send_text($text)
    {
        return $this->evaluate("SEND TEXT \"$text\"");
    }

    /**
     * Cause the channel to automatically hangup at $time seconds in the future.
     * If $time is 0 then the autohangup feature is disabled on this channel.
     *
     * If the channel is hungup prior to $time seconds, this setting has no effect.
     *
     * @link http://www.voip-info.org/wiki-set+autohangup
     * @param integer $time until automatic hangup
     * @return array, see evaluate for return information.
     */
    public function set_autohangup($time = 0)
    {
        return $this->evaluate("SET AUTOHANGUP $time");
    }

    /**
     * Changes the caller ID of the current channel.
     *
     * @link http://www.voip-info.org/wiki-set+callerid
     * @param string $cid example: "John Smith"<1234567>
     *                    This command will let you take liberties with the <caller ID specification> but the format
     *                    shown in the example above works well: the name enclosed in double quotes followed
     *                    immediately by the number inside angle brackets. If there is no name then you can omit it. If
     *                    the name contains no spaces you can omit the double quotes around it. The number must follow
     *                    the name immediately; don't put a space between them. The angle brackets around the number
     *                    are necessary; if you omit them the number will be considered to be part of the name.
     * @return array, see evaluate for return information.
     */
    public function set_callerid($cid)
    {
        return $this->evaluate("SET CALLERID $cid");
    }

    /**
     * Sets the context for continuation upon exiting the application.
     *
     * Setting the context does NOT automatically reset the extension and the priority; if you want to start at the top
     * of the new context you should set extension and priority yourself.
     *
     * If you specify a non-existent context you receive no error indication (['result'] is still 0) but you do get a
     * warning message on the Asterisk console.
     *
     * @link http://www.voip-info.org/wiki-set+context
     * @param string $context
     * @return array, see evaluate for return information.
     */
    public function set_context($context)
    {
        return $this->evaluate("SET CONTEXT $context");
    }

    /**
     * Set the extension to be used for continuation upon exiting the application.
     *
     * Setting the extension does NOT automatically reset the priority. If you want to start with the first priority of
     * the extension you should set the priority yourself.
     *
     * If you specify a non-existent extension you receive no error indication (['result'] is still 0) but you do
     * get a warning message on the Asterisk console.
     *
     * @link http://www.voip-info.org/wiki-set+extension
     * @param string $extension
     * @return array, see evaluate for return information.
     */
    public function set_extension($extension)
    {
        return $this->evaluate("SET EXTENSION $extension");
    }

    /**
     * Enable/Disable Music on hold generator.
     *
     * @link http://www.voip-info.org/wiki-set+music
     * @param boolean $enabled
     * @param string  $class
     * @return array, see evaluate for return information.
     */
    public function set_music($enabled = true, $class = '')
    {
        $enabled = ($enabled) ? 'ON' : 'OFF';
        return $this->evaluate("SET MUSIC $enabled $class");
    }

    /**
     * Set the priority to be used for continuation upon exiting the application.
     *
     * If you specify a non-existent priority you receive no error indication (['result'] is still 0)
     * and no warning is issued on the Asterisk console.
     *
     * @link http://www.voip-info.org/wiki-set+priority
     * @param integer $priority
     * @return array, see evaluate for return information.
     */
    public function set_priority($priority)
    {
        return $this->evaluate("SET PRIORITY $priority");
    }

    /**
     * Sets a variable to the specified value. The variables so created can later be used by later using
     * ${<variablename>} in the dialplan.
     *
     * These variables live in the channel Asterisk creates when you pickup a phone and as such they are both local and
     * temporary. Variables created in one channel can not be accessed by another channel. When you hang up the phone,
     * the channel is deleted and any variables in that channel are deleted as well.
     *
     * @link http://www.voip-info.org/wiki-set+variable
     * @param string $variable is case sensitive
     * @param string $value
     * @return array, see evaluate for return information.
     */
    public function set_variable($variable, $value)
    {
        $value = str_replace("\n", '\n', addslashes($value));
        return $this->evaluate("SET VARIABLE $variable \"$value\"");
    }

    /**
     * Play the given audio file, allowing playback to be interrupted by a DTMF digit. This command is similar to the
     * GET DATA command but this command returns after the first DTMF digit has been pressed while GET DATA can
     * accumulated any number of digits before returning.
     *
     * @example examples/ping.php Ping an IP address
     *
     * @link    http://www.voip-info.org/wiki-stream+file
     * @param string  $filename without extension, often in /var/lib/asterisk/sounds
     * @param string  $escape_digits
     * @param integer $offset
     * @return array, see evaluate for return information. ['result'] is -1 on hangup or error, 0 if playback completes
     *                with no digit received, otherwise a decimal value of the DTMF tone.  Use chr() to convert to
     *                ASCII.
     */
    public function stream_file($filename, $escape_digits = '', $offset = 0)
    {
        return $this->evaluate("STREAM FILE $filename \"$escape_digits\" $offset");
    }

    /**
     * Enable or disable TDD transmission/reception on the current channel.
     *
     * @link http://www.voip-info.org/wiki-tdd+mode
     * @param string $setting can be on, off or mate
     * @return array, see evaluate for return information. ['result'] is 1 on sucess, 0 if the channel is not TDD
     *                capable.
     */
    public function tdd_mode($setting)
    {
        return $this->evaluate("TDD MODE $setting");
    }

    /**
     * Sends $message to the Asterisk console via the 'verbose' message system.
     *
     * If the Asterisk verbosity level is $level or greater, send $message to the console.
     *
     * The Asterisk verbosity system works as follows. The Asterisk user gets to set the desired verbosity at startup
     * time or later using the console 'set verbose' command. Messages are displayed on the console if their verbose
     * level is less than or equal to desired verbosity set by the user. More important messages should have a low
     * verbose level; less important messages should have a high verbose level.
     *
     * @link http://www.voip-info.org/wiki-verbose
     * @param string  $message
     * @param int $level from 1 to 4
     * @return array|null see evaluate for return information.
     */
    public function verbose($message, $level = 1)
    {
        $ret = null;
        foreach (explode("\n", str_replace("\r\n", "\n", print_r($message, true))) as $msg) {
            $ret = $this->evaluate("VERBOSE \"$msg\" $level");
        }
        return $ret;
    }

    /**
     * Waits up to $timeout milliseconds for channel to receive a DTMF digit.
     *
     * @link http://www.voip-info.org/wiki-wait+for+digit
     * @param integer $timeout in millisecons. Use -1 for the timeout value if you want the call to wait indefinitely.
     * @return array, see evaluate for return information. ['result'] is 0 if wait completes with no
     *                         digit received, otherwise a decimal value of the DTMF tone.  Use chr() to convert to
     *                         ASCII.
     */
    public function wait_for_digit($timeout = -1)
    {
        return $this->evaluate("WAIT FOR DIGIT $timeout");
    }


    // *********************************************************************************************************
    // **                             APPLICATIONS                                                                                        **
    // *********************************************************************************************************

    /**
     * Set absolute maximum time of call.
     *
     * Note that the timeout is set from the current time forward, not counting the number of seconds the call has
     * already been up. Each time you call AbsoluteTimeout(), all previous absolute timeouts are cancelled. Will return
     * the call to the T extension so that you can playback an explanatory note to the calling party (the called party
     * will not hear that)
     *
     * @link http://www.voip-info.org/wiki-Asterisk+-+documentation+of+application+commands
     * @link http://www.dynx.net/ASTERISK/AGI/ccard/agi-ccard.agi
     * @param int $seconds allowed, 0 disables timeout
     * @return array, see evaluate for return information.
     */
    public function exec_absolutetimeout($seconds = 0)
    {
        return $this->exec('AbsoluteTimeout', $seconds);
    }

    /**
     * Executes an AGI compliant application.
     *
     * @param string $command
     * @return array, see evaluate for return information. ['result'] is -1 on hangup or if application requested
     *                hangup, or 0 on non-hangup exit.
     * @param string $args
     */
    public function exec_agi($command, $args)
    {
        return $this->exec("AGI $command", $args);
    }

    /**
     * Set Language.
     *
     * @param string $language code
     * @return array, see evaluate for return information.
     */
    public function exec_setlanguage($language = 'en')
    {
        return $this->exec('Set', 'CHANNEL(language)=' . $language);
    }

    /**
     * Do ENUM Lookup.
     *
     * Note: to retrieve the result, use
     *   get_variable('ENUM');
     *
     * @param $exten
     * @return array, see evaluate for return information.
     */
    public function exec_enumlookup($exten)
    {
        return $this->exec('EnumLookup', $exten);
    }

    /**
     * Dial.
     *
     * Dial takes input from ${VXML_URL} to send XML Url to Cisco 7960
     * Dial takes input from ${ALERT_INFO} to set ring cadence for Cisco phones
     * Dial returns ${CAUSECODE}: If the dial failed, this is the errormessage.
     * Dial returns ${DIALSTATUS}: Text code returning status of last dial attempt.
     *
     * @link http://www.voip-info.org/wiki-Asterisk+cmd+Dial
     * @param string  $type
     * @param string  $identifier
     * @param integer $timeout
     * @param string  $options
     * @param string  $url
     * @return array, see evaluate for return information.
     */
    public function exec_dial($type, $identifier, $timeout = null, $options = null, $url = null)
    {
        return $this->exec('Dial', trim("$type/$identifier" . $this->option_delim . $timeout . $this->option_delim . $options . $this->option_delim . $url, $this->option_delim));
    }

    /**
     * Goto.
     *
     * This function takes three arguments: context,extension, and priority, but the leading arguments
     * are optional, not the trailing arguments.  Thuse goto($z) sets the priority to $z.
     *
     * @param string $a
     * @param string $b ;
     * @param string $c ;
     * @return array, see evaluate for return information.
     */
    public function exec_goto($a, $b = null, $c = null)
    {
        return $this->exec('Goto', trim($a . $this->option_delim . $b . $this->option_delim . $c, $this->option_delim));
    }

    // *********************************************************************************************************
    // **                             DERIVED                                                                                             **
    // *********************************************************************************************************

    /**
     * setContext - Set context, extension and priority.
     *
     * @param string $context
     * @param string $extension
     * @param int $priority
     */
    public function setContext($context, $extension = 's', $priority = 1)
    {
        $this->set_context($context);
        $this->set_extension($extension);
        $this->set_priority($priority);
    }

    /**
     * Parse caller id.
     *
     * @example examples/dtmf.php Get DTMF tones from the user and say the digits
     * @example examples/input.php Get text input from the user and say it back
     *
     * "name" <proto:user@server:port>
     *
     * @param string $callerid
     * @return array example: 'Name' => $name, 'Number' => $number
     */
    public function parse_callerid($callerid = null)
    {
        if (is_null($callerid))
            $callerid = $this->request['agi_callerid'];

        $ret = ['name' => '', 'protocol' => '', 'username' => '', 'host' => '', 'port' => ''];
        $callerid = trim($callerid);

        if ($callerid{0} == '"' || $callerid{0} == "'") {
            $d = $callerid{0};
            $callerid = explode($d, substr($callerid, 1));
            $ret['name'] = array_shift($callerid);
            $callerid = join($d, $callerid);
        }

        $callerid = explode('@', trim($callerid, '<> '));
        $username = explode(':', array_shift($callerid));
        if (count($username) == 1)
            $ret['username'] = $username[0];
        else {
            $ret['protocol'] = array_shift($username);
            $ret['username'] = join(':', $username);
        }

        $callerid = join('@', $callerid);
        $host = explode(':', $callerid);
        if (count($host) == 1)
            $ret['host'] = $host[0];
        else {
            $ret['host'] = array_shift($host);
            $ret['port'] = join(':', $host);
        }

        return $ret;
    }

    // *********************************************************************************************************
    // **                             PRIVATE                                                                                             **
    // *********************************************************************************************************

    /**
     * Evaluate an AGI command.
     *
     * @access private
     * @param string $command
     * @return array ('code'=>$code, 'result'=>$result, 'data'=>$data)
     */
    private function evaluate($command)
    {
        $broken = ['code' => 500, 'result' => -1, 'data' => ''];

        // write command
        if (!@fwrite($this->out, trim($command) . "\n")) {
            return $broken;
        }
        fflush($this->out);

        // Read result.  Occasionally, a command return a string followed by an extra new line.
        // When this happens, our script will ignore the new line, but it will still be in the
        // buffer.  So, if we get a blank line, it is probably the result of a previous
        // command.  We read until we get a valid result or asterisk hangs up.  One offending
        // command is SEND TEXT.
        $count = 0;
        do {
            $str = trim(fgets($this->in, 4096));
        } while ($str == '' && $count++ < 5);

        if ($count >= 5) {
            return $broken;
        }

        // parse result
        $ret['code'] = substr($str, 0, 3);
        $str = trim(substr($str, 3));

        if ($str{0} == '-') {
            // we have a multiline response!
            $count = 0;
            $str = substr($str, 1) . "\n";
            $line = fgets($this->in, 4096);
            while (substr($line, 0, 3) != $ret['code'] && $count < 5) {
                $str .= $line;
                $line = fgets($this->in, 4096);
                $count = (trim($line) == '') ? $count + 1 : 0;
            }
            if ($count >= 5) {
                return $broken;
            }
        }

        $ret['result'] = null;
        $ret['data'] = '';
        if ($ret['code'] != self::AGIRES_OK) {
            $ret['data'] = $str;
        } else {
            $parse = explode(' ', trim($str));
            $in_token = false;
            foreach ($parse as $token) {
                if ($in_token) {
                    $ret['data'] .= ' ' . trim($token, '() ');
                    if ($token{strlen($token) - 1} == ')') $in_token = false;
                } elseif ($token{0} == '(') {
                    if ($token{strlen($token) - 1} != ')') $in_token = true;
                    $ret['data'] .= ' ' . trim($token, '() ');
                } elseif (strpos($token, '=')) {
                    $token = explode('=', $token);
                    $ret[$token[0]] = $token[1];
                } elseif ($token != '') {
                    $ret['data'] .= ' ' . $token;
                }
            }
            $ret['data'] = trim($ret['data']);
        }

        // log some errors
        if ($ret['result'] < 0) {
            error_log("$command returned {$ret['result']}");
        }

        return $ret;
    }
}
