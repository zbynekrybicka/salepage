<?php
namespace SalePage;

use Dibi\Connection;
use Latte\Engine;

class SalePage
{

    private $dibi;
    private $sourceXML;

    public function __construct($sourceXML, $db)
    {
        $this->sourceXML = $sourceXML;
        $db = json_decode(file_get_contents($db));

        try {
            if (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false) {
                $dibi = new Connection((array) $db->dev);
            } else {
                $dibi = new Connection((array) $db->prod);
            }
            $this->dibi = $dibi;
        } catch (Exception $e) {
            echo $e;
        }
    }

    private function confirmEmail($id)
    {
        if ($id) {
            $this->dibi->update('email', ['verified' => 1])->where('md5(concat(email, "-", sequence)) = %s', $id)->execute();
        }

    }

    private function loadFromXML($group, $values, $content = null) {
        $xml = simplexml_load_file($this->sourceXML);
        $data = $xml->xpath($group)[0];
        $result = [];
        foreach ($values as $value) {
            $index = str_replace("/", "", $value);
            if (array_key_exists(0, $data->xPath($value))) {
                $result[$index] = $data->xPath($value)[0]->__toString();
            } else {
                die($group . " !!! " . $value);
            }
        }
        if ($content) {
            $result[$content] = $data->xPath($content . '/div')[0]->asXML();
        }
        return $result;
    }

    private function loadXmlGroups($group) {
        $xml = simplexml_load_file($this->sourceXML);
        return $xml->xpath($group);
    }

    private function getFromXml(SimpleXMLElement $xml, $xpath) {
        return $xml->xpath($xpath)[0];
    }


    public function exec()
    {
        $side = $_GET['side'] ?? 'landing';
        $latte = new Engine();
        $latte->setTempDirectory(__DIR__ . '/temp');

        $settings = $this->loadFromXML('/Sales/Settings', ['FBpixel', 'Campaign']);

        if ($side === "landing") {
            $landing = $this->loadFromXML('/Sales/LandingPage',
                ['Panel', 'SuperHeader', 'Header', 'SubHeader', 'Popup/Header', 'Popup/Content', 'Popup/Label'],
                'Content');
            $latte->render(__DIR__ . '/template/landingPage.latte', $landing + $settings);
        } elseif ($side === "email") {
            $this->email();
        } elseif ($side === "email_cron") {
            $this->emailCron();
        } elseif ($side === "thanks") {
            $thanks = $this->loadFromXML('/Sales/ThanksPage', ['Header', 'SubHeader', 'Youtube', 'Image/Url', 'Image/Alt', 'Link/Url', 'Link/Label'], 'Content');
            $latte->render(__DIR__ . '/template/thanksPage.latte', $thanks + $settings);
            $this->emailCron();
        } elseif ($side === "download") {
            $download = $this->loadFromXML('/Sales/DownloadPage', ['Header', 'SubHeader', 'Youtube', 'Image/Url', 'Image/Alt', 'Link/Url', 'Link/Label', 'Download/Url', 'Download/Label'], 'Content');
            $latte->render(__DIR__ . '/template/thanksPage.latte', $download + $settings);
            $this->confirmEmail($_GET['id'] ?? null);
        } elseif ($side === "sale") {
            $sale = $this->loadFromXML('/Sales/SalePage', ['Panel', 'SuperHeader', 'Header', 'SubHeader', 'Youtube', 'Payment/Stripe', 'Payment/Button', 'PS'], 'Content');
            $latte->render(__DIR__ . '/template/salePage.latte', $sale + $settings);
            $this->confirmEmail($_GET['id'] ?? null);
        } elseif ($side === "sold") {
            $sold = $this->loadFromXML('/Sales/SoldPage', ['Header', 'SubHeader', 'Image/Url', 'Image/Alt'], 'Content');
            $latte->render(__DIR__ . '/template/thanksPage.latte', $sold + $settings);
            $md5 = $_GET['id'];
            $this->dibi->update('orders', ['paid' => 1])->where('md5(id) = %s', $md5)->execute();
        } elseif ($side === "cancel") {
            $download = $this->loadFromXML('/Sales/CancelPage', ['Header', 'SubHeader', 'Image/Url', 'Image/Alt', 'Link/Url', 'Link/Label']);
            $latte->render(__DIR__ . '/template/thanksPage.latte', $download + $settings);
            $md5 = $_GET['id'] ?? '';
            $this->dibi->delete('orders')->where('md5(id) = %s', $md5)->execute();
        } elseif ($side === "article") {
            $articles = $this->loadXmlGroups('/Sales/Article');
            /** @var SimpleXMLElement $article */
            foreach ($articles as $article) {
                if ($this->getFromXml($article, 'id') === $_GET['id']) {
                    $latte->render(__DIR__ . '/template/articlePage.latte', [
                            'Panel' => $this->getFromXml($article, 'Panel'),
                            'SuperHeader' => $this->getFromXml($article, 'SuperHeader'),
                            'Header' => $this->getFromXml($article, 'Header'),
                            'SubHeader' => $this->getFromXml($article, 'SubHeader'),
                            'Content' => $article->xpath('Content/div')[0]
                        ] + $settings);
                }
            }
            $this->confirmEmail($_GET['id'] ?? null);
        } elseif ($side === "payment-check") {
            $this->paymentCheck();
        }
    }

    private function email()
    {
        $settings = $this->loadFromXml('/Sales/Settings', ['Campaign']);

        if (array_key_exists('email', $_POST)) {
            $address = $_POST['email'];
            $emailId = $this->dibi->select('id')
                ->from('email')
                ->where('email = %s AND sequence = %s', $address, $settings['Campaign'])
                ->fetchSingle();

            if (!$emailId) {
                $this->dibi->insert('email', ['sequence' => $settings['Campaign'], 'email' => $address])->execute();
                $emailId = $this->dibi->getInsertId();
            }
            $listEmail = $this->loadXmlGroups('/Sales/Email');

            foreach ($listEmail as $email) {
                $templateData = [
                    'server' => $_SERVER['SERVER_NAME'],
                    'md5' => md5($address . '-' . $settings['Campaign'])
                ];
                $content = $email->Content[0]->xpath('div')[0]->asXML();
                $logout = '<p>Pro odhlášení z odběru klikněte <a href="//{$server}/email_cron.php?id={$md5}">zde</a>.</p>';
                $latte = new Engine();
                $latte->setLoader(new \Latte\Loaders\StringLoader(['email' => $content . $logout]));
                $content = $latte->renderToSTring('email', $templateData);
                try {
                    $this->dibi->insert('email_message', [
                        'email_id' => $emailId,
                        'subject' => $email->Subject[0]->__toString(),
                        'sequence_time' => $email->Time[0]->__toString(),
                        'send_at' => date('Y-m-d H:i:s', strtotime($email->Time[0]->__toString())),
                        'content' => $content,
                        'sender' => $email->From[0]->__toString()
                    ])->execute();
                } catch (\Dibi\UniqueConstraintViolationException $e) {

                }
            }
            header('Location: index.php?side=thanks');
        }
    }

    private function process(&$data): int {
        $data = json_decode(file_get_contents('php://input'));
        if (!$data->name) return 1;
        if (!$data->b_street) return 1;
        if (!$data->b_city) return 1;
        if (!$data->b_zip) return 1;
        if (!$data->email) return 1;
        $data->campaign = $_GET['id'];
        try {
            $this->dibi->insert('orders', (array) $data)->execute();
        } catch (Exception $e) {
            http_response_code(400);
            echo $e;
            return 2;
        }
        return 0;
    }

    public function paymentCheck()
    {
        $settings = $this->loadFromXML('/Sales/Settings', ['StripeProduct']);

        if (!$error = $this->process($data)) {
            $httpReferer = explode("?", $_SERVER['HTTP_REFERER']);
            $path = $httpReferer[0];

            $product = $settings['StripeProduct'];
            $result = (object)[];
            $result->mode = 'payment';
            $result->successUrl = $path . '?side=sold&id=' . md5($this->dibi->getInsertId());
            $result->cancelUrl = $path . '?side=cancel&id=' . md5($this->dibi->getInsertId());
            $result->customerEmail = $data->email;
            $result->lineItems = [
                ['price' => $product, 'quantity' => 1],
            ];
            http_response_code(200);
            echo json_encode($result);
        } else {
            http_response_code(400);
            switch ($error) {
                case 1:
                    echo "Nejsou vyplněná povinná pole.";
                    break;

                case 2:
                    echo "Na Vaši e-mailovou adresu již byla objednávka odeslána dříve. Pokud jste ji neodeslali přímo Vy, kontaktujte mě prosím na zbynek.rybicka@gmail.com.";
                    break;

            }
        }
    }

    public function emailLogout()
    {
        try {
            $id = $_GET['id'] ?? '';
            $emailId = $this->dibi->select('id')->from('email')->where('md5(concat(email, "-", sequence)) = %s', $id)->fetchSingle();
            if (!$emailId) {
                throw new Exception('Email not found.');
            }
            $this->dibi->delete('email')->where('id = %u', $emailId)->execute();
            $this->dibi->delete('email_message')->where('email_id = %u AND is_sent = 0', $emailId)->execute();
            $result = true;
        } catch (Exception $ex) {
            $result = false;
        }
        if ($result) {
            echo "Byli jste úspěšně odhlášeni.";
        } else {
            echo "Odhlášení neproběhlo. Pokud jste skutečně kliknuli na odkaz v e-mailu, napište prosím na zbynek.rybicka@gmail.com a já Vás odhlásím ručně. Omlouvám se za vzniklou chybu.";
        }
    }


    private function emailCron()
    {
        $log = [];
        $emails = $this->dibi->select('email_message.id, email, subject, content, sender')
            ->from('email_message')
            ->join('email')->on('email_id = email.id')
            ->where('is_sent = 0 AND send_at <= now()')
            ->fetchAll();
        foreach ($emails as $email) {
            $result = mail($email->email, $email->subject, $email->content, "Content-type: text/html\nFrom: <{$email->sender}>");
            if ($result) {
                $this->dibi->update('email_message', ['is_sent' => 1])->where('id = %u', $email->id)->execute();
            } else {
                $log[] = $email;
            }
        }
        if ($log) {
            $emailLog = __DIR__ . '/emailLog';
            if (!file_exists($emailLog)) {
                mkdir($emailLog, '0777');
            }
            file_put_contents(__DIR__ . '/emailLog/' . date('Y-m-d-H-i-s') . '.json', json_encode($log));
        }

    }
}



