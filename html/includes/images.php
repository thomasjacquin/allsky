<?php

function ListImages()
{
	global $imagesSortOrder;

	$images = array();
	$chosen_day = $_GET['day'];
	$prettyDate = getDatefromDay($chosen_day);
	$num = 0;	// Keep track of count so we can tell user when no files exist.
	$dir = ALLSKY_IMAGES . "/$chosen_day";

	if ($handle = opendir($dir)) {
		while (false !== ($image = readdir($handle))) {
			// Name format: "image-YYYYMMDDHHMMSS.jpg" or .jpg or .png
			if (preg_match('/^\w+-.*\d{14}[.](jpe?g|png)$/i', $image)) {
				$images[] = $image;
				$num += 1;
			}
		}
		closedir($handle);
	}

	if ($num > 0) {
		if ($imagesSortOrder === "descending") {
			arsort($images);
			$sortOrder = "Sorted newest to oldest (descending)";
		} else {
			asort($images);
			$sortOrder = "Sorted oldest to newest (ascending)";
		}
		$sortOrder = "<span class='imagesSortOrder'>$sortOrder</span>";
	} else {
		$sortOrder = "";
	}
	?>

	<link href="documentation/css/viewer.min.css" rel="stylesheet">
	<link href="css/images.css" rel="stylesheet">

	<script src="js/viewer.min.js"></script>
	<script src="js/jquery-viewer.min.js"></script>

	<script type="text/javascript" src="js/jquery-lazy/jquery.lazy.min.js"></script>
	<script type="text/javascript" src="js/jquery-lazy/jquery.lazy.plugins.min.js"></script>

	<script src="js/lightgallery/lightgallery.min.js"></script>
	<script src="js/lightgallery/plugins/thumbnail/lg-thumbnail.umd.js"></script>
	<script src="js/lightgallery/plugins/zoom/lg-zoom.umd.js"></script>
	<script src="js/lightgallery/plugins/fullscreen/lg-fullscreen.umd.js"></script>
	<link type="text/css" rel="stylesheet" href="js/lightgallery/css/lightgallery-bundle.css" />

	<link rel="stylesheet" type="text/css" href="js/tooltipster/dist/css/tooltipster.bundle.min.css" />
	<script type="text/javascript" src="js/tooltipster/dist/js/tooltipster.bundle.min.js"></script>

	<script>
		$(document).ready(function () {

			let lg = document.getElementById('images');
			lg.addEventListener('lgBeforeOpen', (event) => {
				lightGalleryInsyance.refresh();
			});			
			let lightGalleryInsyance = lightGallery(lg, {
				plugins: [lgZoom, lgThumbnail, lgFullscreen]
			});


			$('img.lazy').Lazy({
				attribute: 'data-lazy-src'
			});

			$('.as-tooltip').tooltipster({
				contentAsHTML: true,
				functionBefore: function (instance, helper) {
					let el = $(helper.origin);
					let imageName = el.data('filename');
					let imageDate = el.data('date');
					let imageSRC = el.data('src');
					if (imageSRC === undefined) {
						imageSRC = el.attr('src');
					}
					let content = '<table>\
						<tbody>\
							<tr>\
								<td rowspan=3>\
									<img src="' + imageSRC + '" />\
								</td>\
								<td></td>\
								<td></td>\
							</tr>\
							<tr>\
								<td>&nbsp;</td>\
								<td><strong>File: </strong></td>\
								<td>' + imageName + '</td>\
							</tr>\
							<tr>\
								<td>&nbsp;</td>\
								<td><strong>Date: </strong></td>\
								<td>' + imageDate + '</td>\
							</tr>\
						</tbody>\
					</table>';
					instance.content(content);
				}
			});

		});
	</script>

	<div class="row">
		<div class="col-lg-12">
			<div class="panel panel-primary">
				<div class="panel-heading"><i class="fa fa-bars fa-fw"></i> Image Viewer</div>
				<div class="panel-body">
					<nav class="navbar navbar-default">
						<div class="container-fluid">
							<div class="collapse navbar-collapse" id="oe-module-editor-navbar">
								<ul class="nav navbar-nav">
									<li>
										<h3><?php echo $prettyDate;
										if ($num != 0) {
											echo " - $num Images";
										} ?></h3>
									</li>

								</ul>
								<ul class="nav navbar-nav navbar-right">
									<li>
										<h3><?php echo $sortOrder; ?></h3>
									</li>
								</ul>
							</div>
						</div>
					</nav>
					<div class="row ">
						<div id="images">
							<?php
							if ($num == 0) {
								?>
								<div id="as-no-images" class="as-no-images big">
									<div class="center-full">
										<div class="center-paragraph">
											<h1>There are no images for this date</h1>
										</div>
									</div>
								</div>
								<?php
							} else {
								foreach ($images as $image) {
									echo "<a href='images/$chosen_day/$image' class='left'>";
									if (file_exists("$dir/thumbnails/$image"))
										// "/images" is an alias for ALLSKY_IMAGES in lighttpd
										$t = "/thumbnails";
									else
										$t = "";

									$tt = str_replace('.png', '', $image);
									$tt = str_replace('.jpg', '', $tt);

									$dateTime = getImageTime($image);

									echo "<img src='' id='$tt' data-filename='$image' data-date='$dateTime' data-sub-html='sfsf' data-lazy-src='images/$chosen_day$t/$image' class='thumb thumbBorder lazy as-tooltip' />";

									echo "</a>";
								}
							}
							?>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

<?php
}
?>