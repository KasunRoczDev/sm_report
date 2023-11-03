<?php

if (!function_exists('remote_action')) {
    /**
     * Generate the REMOTE URL to a controller action.
     *
     * @param  string|array  $name
     * @param  mixed  $parameters
     * @param  bool  $absolute
     * @return string
     */
    function remote_action($name, $parameters = [], $absolute = true): string
    {
        $action_url = app('url')->action($name, $parameters, $absolute);

        return return_remote_fn_url($action_url);
    }
}

if (!function_exists('remote_route')) {
    /**
     * Generate the REMOTE URL to a controller route.
     *
     * @param  string|array  $name
     * @param  mixed  $parameters
     * @param  bool  $absolute
     * @return string
     */
    function remote_route($name, $parameters = [], $absolute = true): string
    {
        $action_url = app('url')->route($name, $parameters, $absolute);

        return return_remote_fn_url($action_url);
    }
}

if (!function_exists('remote_report_api_server')) {
    /**
     * Generate the URL for reporting to the API server.
     *
     * @param  string  $url
     * @return string
     */
    function remote_report_api_server(string $url): string
    {
        $base_route = env('REPORT_API_SERVER').'/api/remote-api';
        return $base_route.'/'.$url;
    }
}

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
