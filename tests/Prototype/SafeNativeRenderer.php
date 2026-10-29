<?php
declare(strict_types=1);
namespace RealTest\Prototype;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Test-only safe renderer: fixed public strings, not Throwable messages.
 * MIME negotiation is intentionally deterministic and bounded.
 */
final class SafeNativeRenderer {
    private const SAFE_HEADERS = ['allow', 'retry-after', 'www-authenticate'];

    public function render(Request $request, \Throwable $error, ?PublicErrorDescriptor $approved = null): Response {
        $status=$error instanceof HttpExceptionInterface ? $error->getStatusCode() : 500;
        if ($status < 400 || $status > 599) $status=500;
        $format=$this->negotiate($request);
        if ($approved !== null && $approved->status === $status && $format === 'json') {
            $body=['error'=>[
                'code'=>$approved->code,
                'status'=>$status,
                'safe_message'=>$approved->publicMessage
            ]];
        } elseif ($format==='problem-json'||$format==='problem-xml') {
            $body=[
                'type'=>'about:blank',
                'title'=>$this->title($status),
                'status'=>$status
            ];
        } else {
            $body=['error'=>['code'=>'REQUEST_FAILED','status'=>$status]];
        }

        if ($format === 'problem-json' || $format === 'json') {
            $response=new JsonResponse($body, $status);
            if ($format==='problem-json') $response->headers->set('Content-Type','application/problem+json');
        } elseif ($format==='xml'||$format==='problem-xml') {
            $xml=$format==='problem-xml'
                ? '<?xml version="1.0" encoding="UTF-8"?><problem xmlns="urn:ietf:rfc:7807">'
                    .'<type>about:blank</type><title>'.self::escape($body['title']).'</title><status>'.$status.'</status></problem>'
                : '<?xml version="1.0" encoding="UTF-8"?><error><code>REQUEST_FAILED</code><status>'.$status.'</status></error>';
            $response=new Response($xml,$status,['Content-Type'=>$format==='problem-xml'?'application/problem+xml':'application/xml']);
        } else {
            $response=new Response($this->title($status),$status,['Content-Type'=>'text/plain; charset=UTF-8']);
        }

        $response->headers->set('Cache-Control','no-store, private');
        $response->headers->set('X-Content-Type-Options','nosniff');
        $response->headers->set('Referrer-Policy','no-referrer');
        $response->headers->set('Vary','Accept');

        if ($approved !== null && $approved->status === $status) {
            foreach ($approved->headers as $name=>$value) $response->headers->set($name,$value);
        }
        if ($error instanceof HttpExceptionInterface) {
            foreach ($error->getHeaders() as $name=>$value) {
                if (in_array(strtolower((string)$name),self::SAFE_HEADERS,true)
                    && is_string($value) && strlen($value) <= 160 && !preg_match('/[\r\n]/',$value)) {
                    $response->headers->set($name,$value);
                }
            }
        }
        return $response;
    }

    private static function escape(string $value):string {
        return htmlspecialchars($value, ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }

    private function title(int $status):string {
        return match($status) {
            400=>'Bad Request',401=>'Unauthorized',403=>'Forbidden',
            404=>'Not Found',405=>'Method Not Allowed',409=>'Conflict',
            422=>'Unprocessable Content',429=>'Too Many Requests',
            503=>'Service Unavailable',default=>'Internal Server Error'
        };
    }

    /**
     * Weighted Accept negotiation: the highest-weight supported media type wins.
     * In ties, specificity and original order are used.
     * No implicit XML/HTML content is derived from exceptions.
     */
    public function negotiate(Request $request):string {
        $accept=(string)$request->headers->get('Accept','');
        if ($accept==='')return 'text';
        $entries=[];
        foreach(explode(',',$accept) as $position=>$part){
            $segments=explode(';',trim($part));
            $mime=strtolower(trim(array_shift($segments)));
            $q=1.0;
            foreach($segments as $param){
                if (preg_match('/^\s*q\s*=\s*(0(?:\.\d+)?|1(?:\.0+)?)\s*$/i',$param,$m)) $q=(float)$m[1];
            }
            if($q<=0)continue;
            $format=match($mime){
                'application/problem+json'=>'problem-json',
                'application/problem+xml'=>'problem-xml',
                'application/json','application/*+json'=>'json',
                'application/xml','text/xml','application/*+xml'=>'xml',
                'text/html','text/plain'=>'text',
                '*/*'=>'json',
                default=>null
            };
            if($format!==null)$entries[]=['q'=>$q,'specificity'=>$mime==='*/*'?0:(str_contains($mime,'*')?1:2),'position'=>$position,'format'=>$format];
        }
        usort($entries,static fn($a,$b)=>($b['q']<=>$a['q'])?:($b['specificity']<=>$a['specificity'])?:($a['position']<=>$b['position']));
        return $entries[0]['format']??'text';
    }
}
