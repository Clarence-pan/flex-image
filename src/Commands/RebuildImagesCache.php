<?php


namespace FlexImage\Commands;


use FlexImage\Core\ImageServer;
use Illuminate\Console\Command;

class RebuildImagesCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'images:rebuild-cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rebuild images database';

    public function handle()
    {
        $imageServer = app()->make(ImageServer::class);
        $imageServer->rebuildCache(function ($msg) {
            $this->info($msg);
        });

        return 0;
    }

}