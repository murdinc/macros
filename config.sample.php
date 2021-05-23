<?

class config {

    public $aws_key = '';
    public $aws_secret = '';
    public $s3_bucket = '';
    public $s3_upload_folder = 'cam_1';

    public $new_image_path = '';
    public $backup_image_path = '';

    #initialization
    function __construct() {}

}

$config = new config;

?>