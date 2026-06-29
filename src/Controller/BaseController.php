<?php

declare(strict_types=1);

namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

abstract class BaseController
{
    protected LoggerInterface $logger;
    protected array $settings;

    public function __construct(LoggerInterface $logger, array $settings)
    {
        $this->logger = $logger;
        $this->settings = $settings;
    }

    /**
     * 解析Job路径，支持一级和多级
     * AA/BB/CC -> folder: AA/BB, job: CC
     * AA/BB -> folder: AA, job: BB  
     * AA -> folder: null, job: AA
     */
    protected function parseJobPath(string $path): array
    {
        $path = trim($path, '/');
        $parts = explode('/', $path);
        
        if (count($parts) > 2) {
            $job = array_pop($parts);
            $folder = implode('/', $parts);
            return ['folder' => $folder, 'job' => $job, 'full_path' => $path];
        } elseif (count($parts) == 2) {
            return ['folder' => $parts[0], 'job' => $parts[1], 'full_path' => $path];
        } else {
            return ['folder' => null, 'job' => $parts[0], 'full_path' => $path];
        }
    }

    /**
     * 返回字符型数组格式
     */
    protected function arrayResponse(Response $response, array $data, int $status = 200): Response
    {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($payload);
        
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    /**
     * 返回JSON格式（用于map/list等）
     */
    protected function jsonResponse(Response $response, $data, int $status = 200): Response
    {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($payload);
        
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    /**
     * 返回HTML格式（用于console）
     */
    protected function htmlResponse(Response $response, string $html, int $status = 200): Response
    {
        $response->getBody()->write($html);
        
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withStatus($status);
    }

    /**
     * 验证Job是否存在且类型正确
     */
    protected function validateJobPath(string $jobPath, string $jenkinsUrl, array $authHeader): array
    {
        $parsed = $this->parseJobPath($jobPath);
        $client = new \GuzzleHttp\Client(['verify' => false]);

        if ($parsed['folder']) {
            // 多级路径：先检查folder
            $folderUrl = $jenkinsUrl . '/job/' . str_replace('/', '/job/', $parsed['folder']) . '/api/json';
            
            try {
                $response = $client->get($folderUrl, ['headers' => $authHeader]);
                $folderData = json_decode($response->getBody()->getContents(), true);
                
                if (($folderData['_class'] ?? '') !== 'com.cloudbees.hudson.plugins.folder.Folder') {
                    throw new \RuntimeException('Folder不存在或类型错误');
                }
                
                // 再检查job
                $jobUrl = $folderUrl . '/job/' . $parsed['job'] . '/api/json';
                $response = $client->get($jobUrl, ['headers' => $authHeader]);
                $jobData = json_decode($response->getBody()->getContents(), true);
                
                $jobClass = $jobData['_class'] ?? '';
                if (strpos($jobClass, 'WorkflowJob') === false && 
                    strpos($jobClass, 'WorkflowMultiBranchProject') === false &&
                    strpos($jobClass, 'FreeStyleProject') === false) {
                    throw new \RuntimeException('Job类型不正确');
                }
                
            } catch (\Exception $e) {
                throw new \RuntimeException('Job路径验证失败: ' . $e->getMessage());
            }
        } else {
            // 一级路径：直接检查是否为job
            $jobUrl = $jenkinsUrl . '/job/' . $parsed['job'] . '/api/json';
            
            try {
                $response = $client->get($jobUrl, ['headers' => $authHeader]);
                $jobData = json_decode($response->getBody()->getContents(), true);
                
                $jobClass = $jobData['_class'] ?? '';
                if (strpos($jobClass, 'WorkflowJob') === false && 
                    strpos($jobClass, 'WorkflowMultiBranchProject') === false &&
                    strpos($jobClass, 'FreeStyleProject') === false) {
                    throw new \RuntimeException('非Job类型');
                }
                
            } catch (\Exception $e) {
                throw new \RuntimeException('Job验证失败: ' . $e->getMessage());
            }
        }
        
        return $parsed;
    }
}