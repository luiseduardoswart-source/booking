<?php

/*
 * The messages the website can show. If a line is not listed here, Laravel
 * falls back to lang/nl/validation.php.
 */
return [

    'accepted' => '必须接受:attribute。',
    'after' => ':attribute 必须晚于 :date。',
    'after_or_equal' => ':attribute 不得早于 :date。',
    'array' => ':attribute 必须是列表。',
    'before' => ':attribute 必须早于 :date。',
    'before_or_equal' => ':attribute 不得晚于 :date。',
    'boolean' => ':attribute 必须为是或否。',
    'confirmed' => ':attribute 两次输入不一致。',
    'date' => ':attribute 不是有效日期。',
    'email' => ':attribute 必须是有效的电子邮箱地址。',
    'exists' => '所选的 :attribute 无效。',
    'file' => ':attribute 必须是文件。',
    'image' => ':attribute 必须是图片。',
    'in' => '所选的 :attribute 无效。',
    'integer' => ':attribute 必须是整数。',
    'max' => [
        'array' => ':attribute 不能超过 :max 项。',
        'file' => ':attribute 不能超过 :max KB。',
        'numeric' => ':attribute 不能大于 :max。',
        'string' => ':attribute 不能超过 :max 个字符。',
    ],
    'mimes' => ':attribute 的文件类型必须为::values。',
    'min' => [
        'array' => ':attribute 至少需要 :min 项。',
        'file' => ':attribute 不能小于 :min KB。',
        'numeric' => ':attribute 不能小于 :min。',
        'string' => ':attribute 至少需要 :min 个字符。',
    ],
    'numeric' => ':attribute 必须是数字。',
    'regex' => ':attribute 格式不正确。',
    'required' => ':attribute 为必填项。',
    'required_if' => '当 :other 为 :value 时,:attribute 为必填项。',
    'required_with' => '填写 :values 时,:attribute 为必填项。',
    'same' => ':attribute 和 :other 必须一致。',
    'string' => ':attribute 必须是文本。',
    'unique' => '此 :attribute 已被使用。',
    'uploaded' => ':attribute 上传失败。',
    'url' => ':attribute 必须是有效的网址。',

    'attributes' => [
        'address' => '地址',
        'adults' => '成人人数',
        'arrival' => '入住日期',
        'children' => '儿童人数',
        'city' => '城市',
        'comment' => '评论',
        'coupon' => '折扣码',
        'departure' => '退房日期',
        'email' => '电子邮箱',
        'guests' => '客人人数',
        'signature' => '签名',
        'infants' => '婴儿人数',
        'message' => '留言',
        'name' => '姓名',
        'notes' => '备注',
        'password' => '密码',
        'pets' => '宠物数量',
        'phone' => '电话',
        'photo' => '照片',
        'postal_code' => '邮政编码',
        'rating' => '评分',
        'terms' => '条款',
    ],

];
