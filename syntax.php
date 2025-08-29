<?php

use dokuwiki\HTTP\DokuHTTPClient;
use DOMWrap\Document;

/**
 * DokuWiki Plugin amazonlight (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <andi@splitbrain.org>
 */
class syntax_plugin_amazonlight extends syntax_plugin_doi_isbn
{

    /** @var array what regions to use for the different countries */
    const REGIONS = [
        'us' => 'www.amazon.com',
        'ca' => 'www.amazon.ca',
        'de' => 'www.amazon.de',
        'gb' => 'www.amazon.co.uk',
        'fr' => 'www.amazon.fr',
        'jp' => 'www.amazon.co.jp',
    ];

    public function getConf($setting, $notset = false)
    {
        $config = parent::getConf($setting);
        if(!$config) {
            $doi = plugin_load('syntax', 'doi_isbn');
            $config = $doi->getConf($setting, $notset);
        }
        return $config;
    }

    /**
     * Connect lookup pattern to lexer.
     *
     * @param string $mode Parser mode
     */
    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('\{\{amazon>[\w:\\- =]+\}\}', $mode, 'plugin_amazonlight');
    }

    /** @inheritDoc */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        $match = substr($match, 9, -2);
        list($ctry, $asin) = sexplode(':', $match, 2);

        // no country given?
        if (empty($asin)) {
            $asin = $ctry;
            $ctry = 'us';
        }

        // default parameters...
        $params = array(
            'imgw' => $this->getConf('imgw'),
            'imgh' => $this->getConf('imgh'),
        );
        // ...can be overridden
        list($asin, $more) = sexplode(' ', $asin, 2);
        $params['id'] = $asin;

        if (preg_match('/(\d+)x(\d+)/i', $more, $match)) {
            $params['imgw'] = $match[1];
            $params['imgh'] = $match[2];
        }

        // correct country given?
        if ($ctry === 'uk') $ctry = 'gb';
        if (!preg_match('/^(us|gb|jp|de|fr|ca)$/', $ctry)) {
            $ctry = 'us';
        }
        $params['country'] = $ctry;

        return $params;
    }

    public function render($mode, Doku_Renderer $renderer, $data)
    {
        $region = self::REGIONS[$data['country']];
        $data['url'] = 'https://' . $region . '/dp/' . $data['id'];
        if($this->getConf('partner_'.$data['country'])) {
            $data['url'] .= '/?tag='.$this->getConf('partner_'.$data['country']);
        }

        return parent::render($mode, $renderer, $data);
    }
}

