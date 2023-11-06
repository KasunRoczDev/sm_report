<?php

if (!function_exists('remote_asset')) {
    /**
     * Generate an asset path for the application.
     *
     * @param  string  $path
     * @param  bool|null  $secure
     * @return string
     */
    function remote_asset($path, $secure = null): string
    {
        $asset_url = app('url')->asset($path, $secure);

        return return_remote_fn_url($asset_url);
    }

}

if (!function_exists('remote_url')) {
    function remote_url($url_para): string
    {
        return request()->header('origin').'/'.$url_para;
    }
}

function return_remote_fn_url($asset_url): array|string
{
    if (!empty(parse_url(request()->header('origin'))['host'])) {
        $server_url = request()->server('HTTP_HOST');
        return str_replace($server_url, parse_url(request()->header('origin'))['host'], $asset_url);
    } else {
        $https = request()->server('HTTPS') ? 'https://' : 'http://';
        $server_url = $https.request()->server('HTTP_HOST');
        return str_replace($server_url, request()->header('origin'), $asset_url);
    }
}


