1.0
把WordPress的外链图片下载到本地的一个小工具。



1.1
主要更新内容说明：
设置页面：

新增了一个设置子菜单 cid_settings_page，用户可以在这里选择本地图片目录和默认图片。
新增功能：

允许用户选择和创建本地文件目录。
允许用户上传并选择默认图片。
处理结果分页显示。
用户可以输入多个排除的域名。
处理进度用百分比进度条显示，并预估剩余时间。
进度条：

在 cid_replace_image_links 函数中，增加了进度条的实时更新，通过 JavaScript 更新进度条的宽度。
排除域名：

增加了从表单获取排除域名并在处理过程中使用这些域名来排除图片链接。