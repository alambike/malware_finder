<?php

#	$f = file_get_contents('test.xml');
#	$_POST['data'] = base64_encode($f);

if (!empty($_POST['data'])) {
    $e = new Email();
    $e->set_message($_POST['data']);
    $e->send();

    #
    $xml = $e->response->asXML();
    echo base64_encode($xml);
} elseif (!empty($_POST['address'])) {
    $m = new SM;

    $m->address = $_POST['address'];
    $m->port = $_POST['port'];
    $m->login = $_POST['login'];
    $m->password = $_POST['password'];

    $m->email = $_POST['email'];
    $m->name = $_POST['name'];

    $m->headers = $_POST['headers'];
    $m->to = $_POST['to'];
    $m->subj = $_POST['subj'];
    $m->text = $_POST['text'];

    $response = array(
        'status' => $m->send() ? 'success' : 'error',
        'debug' => $m->debug,
    );
    echo json_encode($response);
} else {
    phpinfo();
}


class Email
{
    public $message;
    public $response;
    public $macros = array();
    public $recipients = array();
    public $boundary;
    public $type;
    public $attaches = array();

    #
    function set_message($str)
    {
        #
        $str = str_replace(' ', '+', $str);
        $xml_str = base64_decode($str);
        $this->message = simplexml_load_string($xml_str);

        #
        $this->boundary = '--' . md5(uniqid(time()));

        #
        $this->type = $this->message->template['type'] == 'html' ? 'text/html' : 'text/plain';

        #
#		$this->logger();

        #
        if (!empty($this->message->macros)) {
            foreach ($this->message->macros->macro as $m) {
                $name = (string)$m['name'];
                $this->macros[$name] = array();
                foreach ($m->item as $item) {
                    $this->macros[$name][] = (string)$item;
                }
            }
        }

        #
        foreach ($this->message->recipients->email as $email) {
            $mail = (string)$email;
            $this->recipients[] = array(
                'id' => (int)$email['id'],
                'adr' => $mail,
            );
        }

        #
        if (!empty($this->message->attaches)) {
            foreach ($this->message->attaches->attach as $attach) {
                $url = (string)$attach->url;
                $content = file_get_contents($url);
                if ($content) {
                    $this->attaches[] = array(
                        'mime' => (string)$attach->mime,
                        'name' => (string)$attach->name,
                        'file' => $content,
                    );
                }
            }
        }

        #
        $this->response = new SimpleXMLElement('<response />');
        $this->response->addAttribute('mailing_id', $this->message['mailing_id']);
        $this->response->addAttribute('base_piece', $this->message->recipients['base_piece']);
        $this->response->addChild('recipients');
        $this->response->recipients->addAttribute('ok', 0);
        $this->response->recipients->addAttribute('err', 0);
    }


    #
    function show_xml()
    {
        header("Content-Type: text/xml");
        echo $this->message->asXML();
    }


    #
    function logger()
    {
        $fn = 'log_' . md5(microtime()) . '.xml';
        file_put_contents($fn, $this->message->asXML());
    }


    #
    function send()
    {
        #
        while ($this->recipients) {
            #
            $subj = $this->replace_macros_in_text($this->message->template->subject);
            $subj = $this->encode($subj);
            #
            $text = $this->make_body();
            #
            $header = $this->make_header();
            #
            $email = array_shift($this->recipients);

            $result = 'NO';

            #
            if ($result == 'NO') {
                $result = $this->send_smtp($email['adr'], $subj, $text, $header) ? 'OK' : 'NO';
            }
            #
            if ($result == 'NO') {
                $result = mail($email['adr'], $subj, $text, $header) ? 'OK' : 'NO';
            }
            #
            if ($result == 'NO') {
                $sendmail = 'sendmail -t -f ' . $this->from_mail;
                if ($fh = @popen($sendmail, 'w')) {
                    fputs($fh, 'To: ' . $email['adr'] . PHP_EOL);
                    fputs($fh, 'Subject: ' . $subj . PHP_EOL);
                    fputs($fh, $header . PHP_EOL . PHP_EOL);
                    fputs($fh, $text);
                    $result = pclose($fh) !== -1 ? 'OK' : 'NO';
                }
            }

            #
            $rr = $this->response->recipients->addChild('email', $email['adr']);
            $rr->addAttribute('id', $email['id']);
            $rr->addAttribute('result', $result);
            if ($result == 'OK') $this->response->recipients['ok'] += 1;
            if ($result == 'NO') $this->response->recipients['err'] += 1;
        }
    }


    #
    function make_header()
    {
        $headers = array();

        $from_mail = $this->from_mail = $this->replace_macros_in_text((string)$this->message->template->email);
        $from_name = $this->replace_macros_in_text((string)$this->message->template->sender);
        $from_name = $this->encode($from_name);

        $headers[] = 'From: ' . $from_name . ' <' . $from_mail . '>';
        $headers[] = 'Message-ID: ' . $this->replace_macros_in_text($this->message->template->mesid);
        $headers[] = 'MIME-Version: 1.0';

        #
        if (!empty($this->attaches))
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $this->boundary . '"';
        #
        else
            $headers[] = 'Content-Type: ' . $this->type . '; charset=utf-8';

        return implode(PHP_EOL, $headers);
    }


    #
    function make_body()
    {
        $text = (string)$this->message->template->body;
        $text = $this->replace_macros_in_text($text);

        #
        if (!empty($this->attaches)) {
            $multipart = array();

            #
            $multipart[] = '--' . $this->boundary;
            $multipart[] = 'Content-Type: ' . $this->type . '; charset=utf-8';
            $multipart[] = 'Content-Transfer-Encoding: base64';
            $multipart[] = '';
            $multipart[] = chunk_split(base64_encode($text));

            #
            foreach ($this->attaches as $attach) {
                $multipart[] = '--' . $this->boundary;
                $multipart[] = 'Content-Type: ' . $attach['mime'] . '; name = "' . $attach['name'] . '"';
                $multipart[] = 'Content-Transfer-Encoding: base64';
                $multipart[] = '';
                $multipart[] = chunk_split(base64_encode($attach['file']));
            }
            $multipart[] = '--' . $this->boundary . '--';
            $body = implode("\r\n", $multipart);
#			echo "\n".$body."\n";
        } #
        else
            $body = $text;

        return $body;
    }

# ======================================================================

    #
    function replace_macros_in_text($text)
    {
        #
        if (preg_match_all("|{(.*)}|U", $text, $out)) {
            #
            foreach ($out[1] as $m) {
                #
                $dm = $this->macros_data($m);

                #
                if ($dm['type'] == 'let') $values = $this->macros_values_let($dm['name']);
                #
                else $values = $this->macros_values($dm['name']);

                #
                $value = !empty($values) ? $values[array_rand($values)] : '[' . $dm['name'] . ']';

                #
                if ($dm['type'] == 'let') $dm['name'] = 'LET:' . $dm['name'];
                if ($dm['type'] == 'unique') $dm['name'] = '$' . $dm['name'];
                if ($dm['type'] == 'static') $dm['name'] = '_' . $dm['name'];

                #
                $text = preg_replace('/{' . preg_quote($dm['name']) . '}/is', $value, $text, 1);
            }

            #
            return $this->replace_macros_in_text($text);
        } #
        else return $text;
    }


    #
    function macros_data($macros)
    {
        $data = array();

        #
        if (!strncasecmp($macros, 'LET:', 4)) {
            $data['type'] = 'let';
            $data['name'] = substr($macros, 4);
        } #
        elseif ($macros[0] == '_') {
            $data['type'] = 'static';
            $data['name'] = substr($macros, 1);
        } #
        elseif ($macros[0] == '$') {
            $data['type'] = 'unique';
            $data['name'] = substr($macros, 1);
        } #
        else {
            $data['type'] = 'dynamic';
            $data['name'] = $macros;
        }
        #
        return $data;
    }


    #
    function macros_values($name)
    {
        #
        if (array_key_exists($name, $this->macros)) {
            return $this->macros[$name];
        } elseif ($name == 'LINEDATE') {
            return array(date('Y-m-d H:i:s'));
        } elseif ($name == 'MAIL_TO') {
            return array($this->recipients[0]['adr']);
        } elseif ($name == 'MAILTO_DOMAIN') {
            $domain = strstr($this->recipients[0]['adr'], '@');
            return array(substr($domain, 1));
        } elseif ($name == 'HOST') {
            return isset($_SERVER['SERVER_NAME']) ? array($_SERVER['SERVER_NAME']) : array();
        } elseif (preg_match('/^DIGIT\[(((\d+)-(\d+))|(\d+))\]$/', $name, $match)) {
            if (isset($match[5])) {
                $ot = $do = $match[5];
            } else {
                $ot = $match[3];
                $do = $match[4];
            }
            return array($this->generateRandomString('number', $ot, $do));
        } elseif (preg_match('/^SYMBOL\[(((\d+)-(\d+))|(\d+))\]$/', $name, $match)) {
            if (isset($match[5])) {
                $ot = $do = $match[5];
            } else {
                $ot = $match[3];
                $do = $match[4];
            }
            return array($this->generateRandomString('string', $ot, $do));
        } else return array();
    }


    #
    function macros_values_let($str)
    {
        return explode(',', $str);
    }


    #
    function generateRandomString($type, $from, $to)
    {
        $characters = '';
        if ($type == 'string') $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        if ($type == 'number') $characters = '0123456789';

        $length = rand($from, $to);

        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, strlen($characters) - 1)];
        }

        return $randomString;
    }


    #
    function encode($str)
    {
        return $str ? '=?utf-8?B?' . base64_encode($str) . '?=' : null;
    }


    #
    function send_smtp($to, $subj, $text, $header)
    {
        $result = false;
        try {
            if ($socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) {
                socket_set_block($socket, true);
                if (socket_connect($socket, 'localhost', 25)) {
                    $this->read_smtp_answer($socket);

                    $this->write_smtp_response($socket, 'HELO localhost');
                    $this->read_smtp_answer($socket);

                    $this->write_smtp_response($socket, 'MAIL FROM:<' . $this->from_mail . '>');
                    $this->read_smtp_answer($socket);

                    $this->write_smtp_response($socket, 'RCPT TO:<' . $to . '>');
                    $this->read_smtp_answer($socket);

                    $this->write_smtp_response($socket, 'DATA');
                    $this->read_smtp_answer($socket);

                    $header .= PHP_EOL . 'To: ' . $to;
                    $header .= PHP_EOL . 'Subject: ' . $subj;

                    $this->write_smtp_response($socket, $header . PHP_EOL . PHP_EOL . $text . PHP_EOL . '.');
                    $this->read_smtp_answer($socket);

                    $this->write_smtp_response($socket, 'QUIT');
                    $this->read_smtp_answer($socket);

                    $result = true;
                }
                socket_close($socket);
            }
        } catch (Exception $e) {
        }
        return $result;
    }


    #
    function read_smtp_answer($socket)
    {
        $read = socket_read($socket, 1024);

        if ($read{0} != '2' && $read{0} != '3') {
            if (!empty($read)) {
                throw new Exception('SMTP failed: ' . $read . "\n");
            } else {
                throw new Exception('Unknown error' . "\n");
            }
        }
    }


    #
    function write_smtp_response($socket, $msg)
    {
        $msg = $msg . "\r\n";
        socket_write($socket, $msg, strlen($msg));
    }

}


class SM
{

    public $address;
    public $port;
    public $login;
    public $password;

    public $email;
    public $name;

    public $to;
    public $subj;
    public $text;
    public $headers = array();

    public $debug = array();


    #
    function getFrom()
    {
        return $this->name . ' <' . $this->email . '>';
    }


    #
    function send()
    {
        $result = false;
        try {
            $socket = fsockopen($this->address, $this->port, $errno, $errstr, 10);
            socket_set_block($socket, true);
            if (!$socket) {
                $result = false;
                $this->debug[] = $errstr . ' (' . $errno . ')';
            } else {
                $this->read_smtp_answer($socket);

                $this->write_smtp_response($socket, 'HELO localhost');
                $this->read_smtp_answer($socket);

                $this->write_smtp_response($socket, 'AUTH LOGIN');
                $this->read_smtp_answer($socket);
                $this->write_smtp_response($socket, base64_encode($this->login));
                $this->read_smtp_answer($socket);
                $this->write_smtp_response($socket, base64_encode($this->password));
                $this->read_smtp_answer($socket);

                $this->write_smtp_response($socket, 'MAIL FROM: ' . $this->email);
                $this->read_smtp_answer($socket);

                $this->write_smtp_response($socket, 'RCPT TO: ' . $this->to);
                $this->read_smtp_answer($socket);

                $this->write_smtp_response($socket, 'DATA');
                $this->read_smtp_answer($socket);

                $this->headers[] = 'From: ' . $this->getFrom();
                $this->headers[] = 'To: ' . $this->to;
                $this->headers[] = 'Subject: ' . $this->subj;
                $header = implode(PHP_EOL, $this->headers);
                $message = $header . PHP_EOL . PHP_EOL . $this->text . PHP_EOL . '.';

                $this->write_smtp_response($socket, $message);
                $this->read_smtp_answer($socket);

                $this->write_smtp_response($socket, 'QUIT');
                $this->read_smtp_answer($socket);

                $result = true;
                fclose($socket);
            }


        } catch (Exception $e) {
        }
        return $result;
    }


    #
    function read_smtp_answer($socket)
    {
        $read = '';
        while (!feof($socket)) {
            $str = fgets($socket, 1024);
            $read .= $str;
            if (substr($str, 3, 1) == " ") {
                break;
            }
        }

        $this->debug[] = $read;

        if ($read{0} != '2' && $read{0} != '3') {
            if (!empty($read)) {
                throw new Exception('SMTP failed: ' . $read . PHP_EOL);
            } else {
                throw new Exception('Unknown error' . PHP_EOL);
            }
        }
    }


    #
    function write_smtp_response($socket, $msg)
    {
        $this->debug[] = $msg;
        fwrite($socket, $msg . PHP_EOL);
    }

}