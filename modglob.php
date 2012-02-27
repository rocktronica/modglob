<?php 

//               _     _     _   
//   _____ ___ _| |___| |___| |_ 
//  |     | . | . | . | | . | . |
//  |_|_|_|___|___|_  |_|___|___|
//                |___|          
//
//  a trivial but hopefully useful PHP file modification notifier
//  http://github.com/rocktronica/modglob
//
//  MIT/GPL licensed or as components allow
//  Not responsible if it breaks your shit
//

// grubbily override GET for CLI
$opts = getopt("l:h:f:m:i:");
if ($opts["l"]) { $_GET["limit"] = $opts["l"]; }
if ($opts["h"]) { $_GET["hours"] = $opts["h"]; }
if ($opts["f"]) { $_GET["format"] = $opts["f"]; }
if ($opts["m"]) { $_GET["mail"] = $opts["m"]; }
if ($opts["i"]) { $_GET["ignore"] = $opts["i"]; }

ini_set('display_errors',1); error_reporting(E_ALL & ~E_NOTICE);
date_default_timezone_set('America/Chicago');

// start render timer
$startTime = microtime();

// couple variables needed before searching
$iLimit = 1000000; // max * to touch
if (is_numeric($_GET["limit"])) { $iLimit = intval($_GET["limit"]); }
$aIgnoreFolders = array("cache"); // list of folder names to ignore
if (isset($_GET["ignore"])) { $aIgnoreFolders = explode(",", $_GET["ignore"]); }

class PhpFile {

	public $name;
	public $folder;
	public $url;
	public $size;
	public $modified;
	public $created;
	public $accessed;
	public $modstamp;
	public $hoursold;
	public $hrsclass;

	public function __construct($filename, $folder){

		$sDateFormat = "y/m/d";
		$stat = stat($filename);

		$this->name = basename($filename,".php");
		$this->folder = trim($folder,"/");
		$this->url = "/".$filename;
		$this->size = round($stat["size"]/1000, 1)."kb";
		$this->modified = date($sDateFormat, $stat["mtime"]);
		$this->created = date($sDateFormat, $stat["ctime"]);
		$this->accessed = date($sDateFormat, $stat["atime"]);
		$this->modstamp = $stat["mtime"];

		// for JS, most efficient to store as CSS class
		// not very semantic, but whatever
		// just make sure predifined values match <select> options below
		$hrs = ceil((time() - $stat["mtime"])/60/60);
		$aModOptions = array(1,24,168,672);
		$aModOptions = array_reverse($aModOptions);
		$this->hoursold = $hrs;
		foreach ($aModOptions as $key => $value) {
			if ($hrs <= $value) {
				$this->hrsclass .= "hrsOld".$value." ";
			}
		}

	} // __construct

} // PhpFile

$phpfiles = array();
$iFiles = 0;
$iFolders = 0;

function getFiles($folder="") {
	global $phpfiles, $iFiles, $iFolders, $iLimit, $aIgnoreFolders;
	$folder = trim($folder, "/");
	if ($folder !== "") { $folder = $folder."/"; }
	foreach (glob($folder."*") as $filename) {
		if ($iFiles>=$iLimit) { break; }
		$iFiles += 1;
		if (strtolower(substr($filename,-4)) === ".php") {
			$phpfile = new Phpfile($filename, $folder);
			$phpfiles[] = $phpfile;
		}
		if (is_dir($filename) && !in_array($filename, $aIgnoreFolders)) {
			$iFolders += 1;
			getFiles($filename);
		}
	}
}
getFiles();

// http://stackoverflow.com/questions/1462503/sort-array-by-object-property-in-php
function quickSort( &$array ) {
	$cur = 1;
	$stack[1]['l'] = 0;
	$stack[1]['r'] = count($array)-1;
	do {
		$l = $stack[$cur]['l'];
		$r = $stack[$cur]['r'];
		$cur--;
		do {
			$i = $l;
			$j = $r;
			$tmp = $array[(int)( ($l+$r)/2 )];
			do {
				while ($array[$i]->hoursold < $tmp->hoursold) { $i++; }
				while($tmp->hoursold < $array[$j]->hoursold) { $j--; }
				if ($i <= $j) {
					$w = $array[$i];
					$array[$i] = $array[$j];
					$array[$j] = $w;
					$i++; $j--;
				}
			} while ($i <= $j);
			if ($i < $r) {
				$cur++;
				$stack[$cur]['l'] = $i;
				$stack[$cur]['r'] = $r;
			}
			$r = $j;
		} while( $l < $r );
	} while( $cur != 0 );
}

// modified w/in last # hours
$iHours = intval($_GET["hours"]);
if ($iHours === 0) { $iHours = 1; }

// format
$sFormat = strtolower($_GET["format"]);
if (empty($sFormat) && php_sapi_name() == 'cli' && empty($_SERVER['REMOTE_ADDR'])) { $sFormat = "text"; } // cli default
if (empty($sFormat)) { $sFormat = "html"; } // web default
if ($sFormat !== "html") {
	$freshFiles = array();
	$iUrlLen = 0;
	foreach ($phpfiles as $file) {
		if ($file->hoursold <= $iHours) {
			$freshFiles[] = $file;
			if (strlen($file->url) > $iUrlLen) { $iUrlLen = strlen($file->url); }
		}
	}
	quickSort($freshFiles);
}
switch ($sFormat) {
	case "text":
		if ($iFiles>=$iLimit) { echo "WARNING: File limit reached.\n"; }
		foreach ($freshFiles as $file) {
			$mailText .= str_pad(trim($file->url,"/"), $iUrlLen+4).date("m/d/y H:m:s", $file->modstamp)."\n";
		}
		break;
	case "dump":
		if ($iFiles>=$iLimit) { echo "WARNING: File limit reached.\n"; }
		$mailText = print_r($freshFiles, true);
		break;
}
if (isset($mailText)) { echo $mailText; }

// mail
if (isset($_GET["mail"]) && isset($mailText)) {
	if (!filter_var($_GET["mail"], FILTER_VALIDATE_EMAIL) ){
		exit("Invalid email address");
	}
	if (count($freshFiles) > 0) {
		$mailSubject = "modglob: ".count($freshFiles)." File";
		if (count($freshFiles) > 1) { $mailSubject .= "s"; }
		$mailSubject .= " Modified";
		mail(
			$_GET["mail"],
			$mailSubject,
			$mailText
		);
	}
}

if ($sFormat !== "html") { exit(); }

?><!doctype html>
<!--[if lt IE 7]><html class="ie6 oldie" lang="en"><![endif]-->
<!--[if IE 7]><html class="ie7 oldie" lang="en"><![endif]-->
<!--[if IE 8]><html class="ie8 oldie" lang="en"><![endif]-->
<!--[if gt IE 8]><!--><html lang="en"><!--<![endif]-->
<head>
	<meta charset="utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
	<title>modglob</title>
	<meta name="robots" content="noindex,nofollow"> 
	<meta name="viewport" content="width=device-width,initial-scale=1">
	<style>
		* { margin: 0; padding: 0; text-align: left; }
		body { font: 14px/20px sans-serif; margin: 30px 40px; color: #333; }
		h1,h2,h3,p,ul,ol,table,header,footer { margin: 0 0 20px; }
		h1 { font-size: 60px; line-height: 60px; }
		h2 { font-size: 38px; line-height: 60px; }
		h3 { font-size: 26px; line-height: 30px; }
		table * { vertical-align: top; }
		th { white-space: nowrap; cursor: pointer; }
		strong,th,label { font-weight: bold; color: #111; }
		tr>* { padding: 0 10px 0 0; }
		tr>*:last-child { padding: 0; }
		tr:nth-child(2n-1) td { background: #eee; }
		a { text-decoration: none; color: #008134; }
		a:hover { color: #00A241; }
		.warning, noscript { background: #333; color: #eee; padding: 5px 10px; }
		.warning strong { color: #fff; }
		label, select { vertical-align: middle; }
		select { border: 1px solid #ccc; margin-left: 10px; }
		th[role]:after { content: "\25b2"; padding-left: 5px; color: #666; font-size: 10px; vertical-align: top; }
		th[role="desc"]:after { content: "\25bc"; }
		#pModified, .trFile { display: none; }
		footer { color: #666; font-size: 12px; }
	</style>
</head>
<body>

	<header>
		<h1><a href="modglob.php">modglob</a></h1>
	</header>
	
	<div>
		<noscript>This is best with JavaScript enabled, love.</noscript>
		<?php if ($iFiles>=$iLimit) { ?>
			<p class="warning"><strong>Warning!</strong> File limit reached.</p>
		<?php } ?>
		<p id="pModified">
			<label>Modified w/in:</label>
			<select id="selModified">
				<option value="1">Last Hour</option>
				<option value="24">Last Day</option>
				<option value="168">Last Week</option>
				<option value="672">Last Month</option>
				<option value="">Last Infinity</option>
			</select>
		</p>
		<table cellpadding="0" cellspacing="0">
			<thead>
				<tr>
					<th data-i="0" class="th0" id="thLastModified">YY/MM/DD</th>
					<th data-i="1" class="th1">Name</th>
					<th data-i="2" class="th2">Size</th>
					<th data-i="3" class="th3">Folder</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($phpfiles as $phpfile) { ?> 
					<tr class="trFile <?php echo $phpfile->hrsclass; ?>" data-modstamp="<?php echo $phpfile->modstamp; ?>">
						<td><?php echo $phpfile->modified; ?></td>
						<td><?php echo "<a href=\"$phpfile->url\">$phpfile->name</a>"; ?></td>
						<td><?php echo $phpfile->size; ?></td>
						<td><?php echo str_replace("/",'/&shy;',$phpfile->folder); ?></td>
					</tr>
				<?php } ?> 
			</tbody>
		</table>
	</div>
	
	<footer>
		<?php $endTime = microtime(); ?>
		<p>Found <?php echo count($phpfiles); ?> PHP files from <?php echo $iFolders; ?> folders with <?php echo $iFiles; ?> files total. Rendered in <?php echo round($endTime, 2); ?> seconds.</p>
	</footer>
	
	<script src="//ajax.googleapis.com/ajax/libs/jquery/1.7/jquery.min.js"></script>
	<script>
		(function(b){var o=!1,d=null,u=parseFloat,j=String.fromCharCode,q=Math.min,l=/(\d+\.?\d*)$/g,g,a=[],h,m,t=9472,f={},c;for(var p=32,k=j(p),r=255;p<r;p++,k=j(p).toLowerCase()){if(!~a.indexOf(k)){a.push(k)}}a.sort();b.tinysort={id:"TinySort",version:"1.3.19",copyright:"Copyright (c) 2008-2012 Ron Valstar",uri:"http://tinysort.sjeiti.com/",licenced:{MIT:"http://www.opensource.org/licenses/mit-license.php",GPL:"http://www.gnu.org/licenses/gpl.html"},defaults:{order:"asc",attr:d,data:d,useVal:o,place:"start",returns:o,cases:o,forceStrings:o,sortFunction:d,charOrder:g}};b.fn.extend({tinysort:function(C,v){if(C&&typeof(C)!="string"){v=C;C=d}var D=b.extend({},b.tinysort.defaults,v),K,X=this,T=b(this).length,aa={},G=!(!C||C==""),I=!(D.attr===d||D.attr==""),P=D.data!==d,z=G&&C[0]==":",A=z?X.filter(C):X,J=D.sortFunction,N=D.order=="asc"?1:-1,B=[];if(D.charOrder!=g){g=D.charOrder;if(!D.charOrder){m=false;t=9472;f={};c=h=d}else{h=a.slice(0);m=false;for(var Z=[],s="",H="z",R=g.length,V=0;V<R;V++){var x=g[V],Y=x.charCodeAt(),F=Y>96&&Y<123;if(!F){if(x=="{"){var ac=g.substr(V+1).match(/[^}]*/)[0],O=ac.split("="),y=D.cases?O[0]:O[0].toLowerCase(),W=O.length>1?O[1]:j(t++);Z.push(W);f[y]=W;V+=ac.length+1;m=true}else{Z.push(x)}}if(Z.length&&(F||V===R-1)){var w=Z.join("");s+=w;b.each(w,function(i,ad){h.splice(h.indexOf(ad),1)});var Q=Z.slice(0);Q.splice(0,0,h.indexOf(H)+1,0);Array.prototype.splice.apply(h,Q);Z.length=0}if(V+1===R){c=new RegExp("["+s+"]","gi")}else{if(F){H=x}}}}}if(!J){J=D.order=="rand"?function(){return Math.random()<0.5?1:-1}:function(ap,an){var ao=o,ah=!D.cases?n(ap.s):ap.s,af=!D.cases?n(an.s):an.s;if(!D.forceStrings){var ae=ah&&ah.match(l),aq=af&&af.match(l);if(ae&&aq){var am=ah.substr(0,ah.length-ae[0].length),al=af.substr(0,af.length-aq[0].length);if(am==al){ao=!o;ah=u(ae[0]);af=u(aq[0])}}}var ad=N*(ah<af?-1:(ah>af?1:0));if(!ao&&D.charOrder){if(m){for(var ar in f){var ag=f[ar];ah=ah.replace(ar,ag);af=af.replace(ar,ag)}}if(ah.match(c)!==d||af.match(c)!==d){for(var ak=0,aj=q(ah.length,af.length);ak<aj;ak++){var ai=h.indexOf(ah[ak]),i=h.indexOf(af[ak]);if(ad=N*(ai<i?-1:(ai>i?1:0))){break}}}}return ad}}X.each(function(af,ag){var ah=b(ag),ad=G?(z?A.filter(ag):ah.find(C)):ah,ai=P?ad.data(D.data):(I?ad.attr(D.attr):(D.useVal?ad.val():ad.text())),ae=ah.parent();if(!aa[ae]){aa[ae]={s:[],n:[]}}if(ad.length>0){aa[ae].s.push({s:ai,e:ah,n:af})}else{aa[ae].n.push({e:ah,n:af})}});for(K in aa){aa[K].s.sort(J)}for(K in aa){var S=aa[K],U=[],ab=T,M=[0,0],V;switch(D.place){case"first":b.each(S.s,function(ad,ae){ab=q(ab,ae.n)});break;case"org":b.each(S.s,function(ad,ae){U.push(ae.n)});break;case"end":ab=S.n.length;break;default:ab=0}for(V=0;V<T;V++){var E=e(U,V)?!o:V>=ab&&V<ab+S.s.length,L=(E?S.s:S.n)[M[E?0:1]].e;L.parent().append(L);if(E||!D.returns){B.push(L.get(0))}M[E?0:1]++}}X.length=0;Array.prototype.push.apply(X,B);return X}});function n(i){return i&&i.toLowerCase?i.toLowerCase():i}function e(v,x){for(var w=0,s=v.length;w<s;w++){if(v[w]==x){return !o}}return o}b.fn.TinySort=b.fn.Tinysort=b.fn.tsort=b.fn.tinysort})(jQuery);</script>
	<script defer>
		$.fn.ready(function(){

			(function(){
				var aAsc = [];
				function sortTable(nr) {
					aAsc[nr] = aAsc[nr]=='desc'?'asc':'desc';
					$("tbody").find("tr").tsort('td:eq('+nr+')',{order:aAsc[nr]});
					$("th").removeAttr("role").eq(nr).attr("role", aAsc[nr]);
				}
				$("th").on("click", function(){
					sortTable(parseInt($(this).data("i"),10));
				}).filter("#thLastModified").click();
			}());

			var $rows = $(".trFile");
			var iHours = <?php echo $iHours; ?>;
			var $selModified = $("#selModified");

			// if hours param matches a select option, select it
			$selModified.find("option[value='" + iHours + "']").attr("selected", "selected");

			$selModified.on("change", function(){
				var iHrsOld = parseInt($selModified.val(), 10);
				console.log("iHrsOld", iHrsOld);
				if (isNaN(iHrsOld)) {
					$rows.show();
				} else {
					console.log("!isNaN(iHrsOld)");
					$rows.hide().filter(".hrsOld" + iHrsOld).show();
				}
			}).trigger("change");

			$("#pModified").show();

		});
	</script>
	<!--[if lt IE 7 ]>
		<script src="//ajax.googleapis.com/ajax/libs/chrome-frame/1.0.3/CFInstall.min.js"></script>
		<script>window.attachEvent('onload',function(){CFInstall.check({mode:'overlay'})})</script>
	<![endif]-->
	
</body>
</html>