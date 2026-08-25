<?php

/**
 * 评论模板
 * @author Seaton Jiang <hi@seatonjiang.com>
 * @author Dylan Li (Kratos-plus fork) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 * @version 2022.01.26
 */

if (isset($_SERVER['SCRIPT_FILENAME']) && 'comments.php' == basename($_SERVER['SCRIPT_FILENAME'])) {
	die();
}
require get_template_directory() . '/pages/page-smilies.php';
if (comments_open()) { ?>
	<div class="comments" id="comments">
		<div id="respond" class="comment-respond mt-3">

			<?php if (get_option('comment_registration') && !is_user_logged_in()) : ?>
				<div class="error text-center">
					<?php printf(__('您需要 <a href="%s">登录</a> 之后才可以评论', 'kratos'), wp_login_url(get_permalink())); ?>
				</div>
			<?php else : ?>
				<form id="commentform" name="commentform" action="<?php echo get_option('siteurl'); ?>/wp-comments-post.php" method="post">
					<div class="comment-form">
						<?php if (!is_user_logged_in()) :
							$req_name_email = (bool) get_option('require_name_email');
							$req_attr = $req_name_email ? ' required="required"' : '';
						?>
							<div class="comment-info mb-3 row">
								<div class="col-md-6 comment-form-author">
									<div class="input-group">
										<span class="input-group-text"><i class="kicon i-user"></i></span>
										<input class="form-control" id="author" placeholder="<?php _e('昵称', 'kratos'); ?>" name="author" type="text"<?php echo $req_attr; ?> value="<?php echo esc_attr($commenter['comment_author']); ?>">
									</div>
								</div>
								<div class="col-md-6 mt-3 mt-md-0 comment-form-email">
									<div class="input-group">
										<span class="input-group-text"><i class="kicon i-cemail"></i></span>
										<input id="email" class="form-control" name="email" placeholder="<?php _e('邮箱', 'kratos'); ?>" type="email"<?php echo $req_attr; ?> value="<?php echo esc_attr($commenter['comment_author_email']); ?>">
									</div>
								</div>
								<div class="col-md-6 mt-3 comment-form-author">
									<div class="input-group">
										<span class="input-group-text"><i class="kicon i-url"></i></span>
										<input class="form-control" id="url" placeholder="<?php _e('网址', 'kratos'); ?>" name="url" type="url" value="<?php echo esc_attr($commenter['comment_author_url']); ?>">
									</div>
								</div>
							</div>
						<?php endif; ?>
						<div class="comment-textarea">
							<textarea class="form-control" id="comment" name="comment" rows="7" required="required" placeholder="<?php echo esc_attr((string) kratos_option('g_comment_placeholder', __('说点什么吧…', 'kratos'))); ?>"></textarea>
							<div class="text-bar clearfix">
								<div class="tool float-start">
									<a class="addbtn" href="#" id="addsmile"><i class="kicon i-face"></i></a>
									<div class="smile">
										<div class="clearfix">
											<?php echo $smilies; ?>
										</div>
									</div>
								</div>
								<div class="float-end">
									<?php comment_form_title(''); ?>
									<?php cancel_comment_reply_link(__('取消回复', 'kratos')); ?>
									<input name="submit" type="submit" id="submit" class="btn btn-primary" value="<?php _e('提交评论', 'kratos'); ?>">
								</div>
							</div>
						</div>
					</div>
					<?php comment_id_fields(); ?>
					<?php do_action('comment_form_after'); ?>
					<?php do_action('comment_form', $post->ID); ?>
				</form>
			<?php endif; ?>
		</div>
		<h3 class="title"><?php if (is_single()) {
								_e('文章评论', 'kratos');
							} else {
								_e('评论内容', 'kratos');
							} ?></h3>
		<?php if (is_singular()) {
			if (function_exists('kratos_render_sticky_comments')) echo kratos_render_sticky_comments(get_the_ID());
			if (function_exists('kratos_render_hot_comments'))    echo kratos_render_hot_comments(get_the_ID());
		} ?>
		<div class="list">
			<?php if (get_comments_number() > 0) : ?>
				<?php wp_list_comments('type=comment&callback=comment_callbacks'); ?>
			<?php else : ?>
				<div class="comment-empty text-center"><?php _e('还没有评论，快来抢沙发吧~', 'kratos'); ?></div>
			<?php endif; ?>
		</div>
		<div id="commentpage" class="paginations my-2">
			<?php paginate_comments_links(array(
				'prev_text' => '<i class="kicon i-larrows"></i>',
				'next_text' => '<i class="kicon i-rarrows"></i>',
				'type'      => 'plain',
			)); ?>
		</div>
	</div>
<?php } ?>
