<?php

/*
 +-----------------------------------------------------------------------+
 | This file is part of the Roundcube Webmail client                     |
 |                                                                       |
 | Copyright (C) The Roundcube Dev Team                                  |
 | Copyright (C) Kolab Systems AG                                        |
 |                                                                       |
 | Licensed under the GNU General Public License version 3 or            |
 | any later version with exceptions for skins & plugins.                |
 | See the README file for a full license statement.                     |
 |                                                                       |
 | PURPOSE:                                                              |
 |   MIME message parsing utilities derived from Mail_mimeDecode         |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 | Author: Aleksander Machniak <alec@alec.pl>                            |
 | Author: Richard Heyes <richard@phpguru.org>                           |
 +-----------------------------------------------------------------------+
*/

/**
 * Class for parsing MIME messages
 */
class rcube_mime_decode
{
    /**
     * Class configuration parameters.
     *
     * @var array
     */
    protected $params = [
        'include_bodies' => true,
        'decode_bodies' => true,
        'decode_headers' => true,
        'crlf' => "\r\n",
        'default_charset' => RCUBE_CHARSET,
    ];

    /**
     * Constructor.
     *
     * Sets up the object, initialize the variables, and splits and
     * stores the header and body of the input.
     *
     * @param array $params An array of various parameters that determine various things:
     *                      include_bodies - Whether to include the body in the returned object.
     *                      decode_bodies  - Whether to decode the bodies of the parts. (Transfer encoding)
     *                      decode_headers - Whether to decode headers
     *                      crlf           - CRLF type to use (CRLF/LF/CR)
     */
    public function __construct($params = [])
    {
        if (!empty($params)) {
            $this->params = array_merge($this->params, (array) $params);
        }
    }

    /**
     * Performs the decoding process.
     *
     * @param string $input   The input to decode
     * @param bool   $convert Convert result to rcube_message_part structure
     *
     * @return rcube_message_part|false Decoded results or False on failure
     */
    public function decode($input, $convert = true)
    {
        [$header, $body] = $this->splitBodyHeader($input);

        $struct = $this->do_decode($header, $body);

        if ($struct && $convert) {
            $struct = $this->structure_part($struct);
        }

        if ($struct) {
            $struct->size = strlen($input);
        }

        return $struct;
    }

    /**
     * Performs the decoding. Decodes the body string passed to it
     * If it finds certain content-types it will call itself in a
     * recursive fashion
     *
     * @param string $headers       Header section
     * @param string $body          Body section
     * @param string $default_ctype Default content type
     *
     * @return rcube_message_part|false Decoded results or False on error
     */
    protected function do_decode($headers, $body, $default_ctype = 'text/plain')
    {
        $return = new rcube_message_part();
        $headers = $this->parseHeaders($headers);

        foreach ($headers as $value) {
            $header_name = strtolower($value['name']);

            if (isset($return->headers[$header_name]) && !is_array($return->headers[$header_name])) {
                $return->headers[$header_name] = [$return->headers[$header_name]];
                $return->headers[$header_name][] = $value['value'];
            } elseif (isset($return->headers[$header_name])) {
                $return->headers[$header_name][] = $value['value'];
            } else {
                $return->headers[$header_name] = $value['value'];
            }

            switch ($header_name) {
                case 'content-type':
                    $content_type = $this->parseHeaderValue($value['value']);

                    if (preg_match('/([0-9a-z+.-]+)\/([0-9a-z+.-]+)/i', $content_type['value'], $regs)) {
                        $return->ctype_primary = $regs[1];
                        $return->ctype_secondary = $regs[2];
                    }

                    if (!empty($content_type['other'])) {
                        $return->ctype_parameters = array_merge((array) $return->ctype_parameters, (array) $content_type['other']);
                    }

                    break;
                case 'content-disposition':
                    $content_disposition = $this->parseHeaderValue($value['value']);
                    $return->disposition = $content_disposition['value'];

                    if (!empty($content_disposition['other'])) {
                        $return->d_parameters = array_merge((array) $return->d_parameters, (array) $content_disposition['other']);
                    }

                    break;
                case 'content-transfer-encoding':
                    $content_transfer_encoding = $this->parseHeaderValue($value['value']);
                    break;
            }
        }

        if (isset($content_type)) {
            $ctype = strtolower($content_type['value']);

            switch ($ctype) {
                case 'text/plain':
                    $encoding = $content_transfer_encoding['value'] ?? '7bit';

                    if ($this->params['include_bodies']) {
                        $return->body = $this->params['decode_bodies'] ? rcube_mime::decode($body, $encoding) : $body;
                    }

                    break;
                case 'text/html':
                    $encoding = $content_transfer_encoding['value'] ?? '7bit';

                    if ($this->params['include_bodies']) {
                        $return->body = $this->params['decode_bodies'] ? rcube_mime::decode($body, $encoding) : $body;
                    }

                    break;
                case 'multipart/digest':
                case 'multipart/alternative':
                case 'multipart/related':
                case 'multipart/mixed':
                case 'multipart/signed':
                case 'multipart/encrypted':
                    if (!isset($content_type['other']['boundary'])) {
                        return false;
                    }

                    $default_ctype = $ctype === 'multipart/digest' ? 'message/rfc822' : 'text/plain';
                    $parts = $this->boundarySplit($body, $content_type['other']['boundary']);

                    for ($i = 0; $i < count($parts); $i++) {
                        [$part_header, $part_body] = $this->splitBodyHeader($parts[$i]);
                        $return->parts[] = $this->do_decode($part_header, $part_body, $default_ctype);
                    }

                    break;
                case 'message/rfc822':
                    $obj = new self($this->params);
                    $return->parts[] = $obj->decode($body, false);
                    unset($obj);

                    if ($this->params['include_bodies']) {
                        $return->body = $this->params['decode_bodies'] ? rcube_mime::decode($body) : $body;
                    }

                    break;
                default:
                    if ($this->params['include_bodies']) {
                        $encoding = !empty($content_transfer_encoding['value']) ? $content_transfer_encoding['value'] : '7bit';
                        $return->body = $this->params['decode_bodies'] ? rcube_mime::decode($body, $encoding) : $body;
                    }

                    break;
            }
        } else {
            $ctype = explode('/', $default_ctype);
            $return->ctype_primary = $ctype[0];
            $return->ctype_secondary = $ctype[1];

            if ($this->params['include_bodies']) {
                $return->body = $this->params['decode_bodies'] ? rcube_mime::decode($body) : $body;
            }
        }

        return $return;
    }

    /**
     * Given a string containing a header and body
     * section, this function will split them (at the first
     * blank line) and return them.
     *
     * @param string $input Input to split apart
     *
     * @return array Contains header and body section
     */
    protected function splitBodyHeader($input)
    {
        $pos = strpos($input, $this->params['crlf'] . $this->params['crlf']);
        if ($pos === false) {
            return [$input, ''];
        }

        $crlf_len = strlen($this->params['crlf']);
        $header = substr($input, 0, $pos);
        $body = substr($input, $pos + 2 * $crlf_len);

        if (str_ends_with($body, $this->params['crlf'])) {
            $body = substr($body, 0, -$crlf_len);
        }

        return [$header, $body];
    }

    /**
     * Parse headers given in $input and return as assoc array.
     *
     * @param ?string $input Headers to parse
     *
     * @return array Contains parsed headers
     */
    protected function parseHeaders($input)
    {
        $return = [];

        if (is_string($input) && $input !== '') {
            // Unfold the input
            $input = preg_replace('/' . $this->params['crlf'] . "(\t| )/", ' ', $input);
            $headers = explode($this->params['crlf'], trim($input));

            foreach ($headers as $value) {
                $hdr_name = substr($value, 0, $pos = strpos($value, ':'));
                $hdr_value = substr($value, $pos + 1);

                if (isset($hdr_value[0]) && $hdr_value[0] == ' ') {
                    $hdr_value = substr($hdr_value, 1);
                }

                $return[] = [
                    'name' => $hdr_name,
                    'value' => $this->params['decode_headers'] ? $this->decodeHeader($hdr_value) : $hdr_value,
                ];
            }
        }

        return $return;
    }

    /**
     * Function to parse a header value, extract first part, and any secondary
     * parts (after ;) This function is not as robust as it could be.
     * Eg. header comments in the wrong place will probably break it.
     *
     * @param string $input Header value to parse
     *
     * @return array Contains parsed result
     */
    protected function parseHeaderValue($input)
    {
        $parts = preg_split('/;\s*/', $input);
        $return = [];

        if (!empty($parts)) {
            $return['value'] = trim($parts[0]);

            for ($n = 1; $n < count($parts); $n++) {
                if (preg_match('/^([[:alnum:]]+)="?([^"]*)"?+/', $parts[$n], $matches)) {
                    $return['other'][strtolower($matches[1])] = $matches[2];
                }
                // Support RFC2231 encoding
                elseif (preg_match('/^([[:alnum:]]+)\*([0-9]*)\*?="*([^"]+)"*/', $parts[$n], $matches)) {
                    $key = strtolower($matches[1]);
                    $val = $matches[3];

                    if (preg_match("/^(([^']*)'[^']*')/", $val, $m)) {
                        $val = rawurldecode(substr($val, strlen($m[0])));
                    }

                    if (isset($return['other'][$key])) {
                        $return['other'][$key] .= $val;
                    } else {
                        $return['other'][$key] = $val;
                    }
                }
            }
        } else {
            $return['value'] = trim($input);
        }

        return $return;
    }

    /**
     * This function splits the input based on the given boundary
     *
     * @param string $input    Input to parse
     * @param string $boundary Boundary
     *
     * @return array Contains array of resulting mime parts
     */
    protected function boundarySplit($input, $boundary)
    {
        $tmp = explode('--' . $boundary, $input);
        $parts = [];

        for ($i = 1; $i < count($tmp) - 1; $i++) {
            $parts[] = $tmp[$i];
        }

        return $parts;
    }

    /**
     * Given a header, this function will decode it according to RFC2047.
     * Probably not *exactly* conformant, but it does pass all the given
     * examples (in RFC2047).
     *
     * @param string $input Input header value to decode
     *
     * @return string Decoded header value
     */
    protected function decodeHeader($input)
    {
        return rcube_mime::decode_mime_string($input, $this->params['default_charset']);
    }

    /**
     * Recursive method to convert a rcube_mime_decode structure
     * into a rcube_message_part object.
     *
     * @param rcube_message_part $part   A message part struct
     * @param int                $count  Part count
     * @param string             $parent Parent MIME ID
     *
     * @return rcube_message_part
     *
     * @see self::decode()
     */
    protected function structure_part($part, $count = 0, $parent = '')
    {
        $struct = new rcube_message_part();
        $struct->mime_id = $part->mime_id ?: (empty($parent) ? (string) $count : "{$parent}.{$count}");
        $struct->headers = $part->headers;
        $struct->mimetype = $part->ctype_primary . '/' . $part->ctype_secondary;
        $struct->ctype_primary = $part->ctype_primary;
        $struct->ctype_secondary = $part->ctype_secondary;
        $struct->ctype_parameters = $part->ctype_parameters;
        $struct->d_parameters = $part->d_parameters;

        if (!empty($part->headers['content-transfer-encoding'])) {
            $struct->encoding = $part->headers['content-transfer-encoding'];
        }

        if (!empty($part->ctype_parameters['charset'])) {
            $struct->charset = $part->ctype_parameters['charset'];
        }

        // determine filename
        if (!empty($part->d_parameters['filename'])) {
            $filename = $part->d_parameters['filename'];
        } elseif (!empty($part->ctype_parameters['name'])) {
            $filename = $part->ctype_parameters['name'];
        }

        if (!empty($filename)) {
            if (empty($this->params['decode_headers'])) {
                $filename = $this->decodeHeader($filename);
            }

            $struct->filename = $filename;
        }

        $struct->body = $part->body;
        $struct->size = is_string($part->body) ? strlen($part->body) : 0;
        $struct->disposition = $part->disposition;

        $count = 0;
        foreach ((array) $part->parts as $child_part) {
            $struct->parts[] = $this->structure_part($child_part, ++$count, $struct->mime_id);
        }

        return $struct;
    }
}
