# v2board部署教程

- 1.第一步安装aapanel

  

  ```
  URL=https://www.aapanel.com/script/install_7.0_en.sh && if [ -f /usr/bin/curl ];then curl -ksSO "$URL" ;else wget --no-check-certificate -O install_7.0_en.sh "$URL";fi;bash install_7.0_en.sh forum
  ```

  

- 2.安装Nginx最新版采用编译安装

  

  ![95c5f135853569a90d33a371ec3379d8.png](https://i.mji.rip/2026/08/08/95c5f135853569a90d33a371ec3379d8.png)

  

- 3.安装MySQL 5.7

  

  ![d16e2b106ccc3ed178c8d94028662c02.png](https://i.mji.rip/2026/08/08/d16e2b106ccc3ed178c8d94028662c02.png)

  

- 4.安装PHP 8.1

  

  ![02fd4a53c39dbc8a2a8c0f0dc45edf7a.png](https://i.mji.rip/2026/08/08/02fd4a53c39dbc8a2a8c0f0dc45edf7a.png)

  一. 安装完成后，打开PHP设置，然后打开相关的页面，在 Install extensions 安装部署v2board所需要的拓展包（这里安装 fileinfo、redis）即可，其他东西不需要安装

  二. 这一步完成之后，找到 Disabled functions，这里需要接触禁用几个函数才可以完成下一步部署安装，这些函数分别是（exec、shell_exec、pcntl_signal_dispatch、pcntl_fork、pcntl_wait、putenv、proc_open、pcntl_alarm、pcntl_signal）

- 5.安装PHPAdmin最新版即可

- 6.安装Supervisor最新版

- 7.安装Fail2ban Manager最新版

- 8.在网站PHP处，选择添加站点

  

  ![image-20260808165833572](C:\Users\Administrator\AppData\Roaming\Typora\typora-user-images\image-20260808165833572.png)

- 9.直接点确定，完成操作

- 10.创建完成站点后，然后打开站点目录，删除站点目录下的所有文件

- 11.然后使用git命令将分支文件拉取下来

  ```
  git clone https://github.com/kexue-aihao/v2board.git ./
  ```

- 12.拉取下来后授予拉取下来的文件 755 权限

- 13.拉取下来后安装

  ```
  sh init.sh
  ```

- 14.安装完成后需要设置网站运行目录

  

  ![0c740d649fc799b5018d6eead6b67a68.png](https://i.mji.rip/2026/08/08/0c740d649fc799b5018d6eead6b67a68.png)

- 15.配置URL重写

```
location /downloads {
}

location / {
try_files $uri $uri/ @backend;
}

location ~ (/config/|/manage/|/webhook|/payment|/order|/theme/) {
try_files $uri $uri/ /index.php$is_args$query_string;
}

location @backend {
proxy_set_header Host $http_host;
proxy_pass http://127.0.0.1:6600;
}

location ~ .*\.(js|css)?$
{
expires 1h;
error_log off;
access_log /dev/null; 
}
```

- 16.配置完URL重写后配置计划任务

```
php /www/wwwroot/v2board/artisan schedule:run
```

- 17.这一步配置完配置Supervisor

  

  ![efbafc56ab0befa8f0d1e3b503c49282.png](https://i.mji.rip/2026/08/08/efbafc56ab0befa8f0d1e3b503c49282.png)



![87408a88db7db0448688357f336cb0a6.png](https://i.mji.rip/2026/08/08/87408a88db7db0448688357f336cb0a6.png)

- 18.这里配置完成后，最后配置SSL证书，站点就可以使用了