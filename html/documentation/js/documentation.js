// Modify URLs on the GitHub server so we can view pages there OR on a Pi.
// Also, allow files to be included since GitHub doesn't have PHP.

var debug = true;

// branch is updated during installation.
var branch = "master";

// On the Pi, URLs will either begin with "/documentation" (which is a web
// alias to ~/allsky/documentation), or will be a relative path.
// Either way, use it as is.

// On GitHub, all URLs must have "/documentation/" in them - either because they were defined
// that way in the html file, or we add them in this script.
var documentation_URL = "documentation";
var documentation_URL_full = "/" + documentation_URL + "/";

var onGitHub;

var git_hostname = "htmlpreview.github.io";
var preURL_href, preURL_src;			// What gets prepended to the desired URL.

var git_preURL_href = "https://" + git_hostname + "/?";
var git_raw = "https://raw.githubusercontent.com/AllskyTeam/allsky/";

if (debug) console.log("location.hostname=" + location.hostname + ", git_hostname=" + git_hostname);
if (location.hostname == git_hostname) {
	onGitHub = true;

	// On GitHub, the URLs for anchors (href=) and images (src=) are different.
	// anchors need get_preURL_href prepended to them.
	// image URLs and files that are included don't need get_preURL_href.
	preURL_href = git_preURL_href + git_raw + branch + "/html";
	preURL_src = git_raw + branch + "/html";
} else {
	onGitHub = false;		// on a Pi
	preURL_href = "";
	preURL_src = "";
}
if (debug) console.log("preURL_href=" + preURL_href + ", preURL_src=" + preURL_src);
preURL_include = preURL_src;

// Convert URL for all tags with an "allsky=true" attribute.
// The specified URL will never be a full URL, i.e., it'll start with "/" or a dir/file.
function convertURL() {
	allTags = document.getElementsByTagName("*");
	for (var i = 0; i < allTags.length; i++) {
		var elmnt = allTags[i];

		if (elmnt.getAttribute("external")) {
			elmnt.innerHTML += " <i class='fa fa-external-link-alt fa_external'></i>";
			if (debug) console.log("elmnt=", elmnt);

			elmnt["target"] = "_blank";
			elmnt["title"] = "Opens in new tab/window";
		}

		/*
			Search for elements with an "allsky" attribute which means
			we need to update the URL.
		*/
		if (! elmnt.getAttribute("allsky")) continue;	// "allsky" not defined - ignore tag

		var url = null;
		var preURL;

		// Most of the elements with "allsky" are href, so look for them first.
		var attribute = "href";
		url = elmnt.getAttribute(attribute);
		if (url) {
			preURL = preURL_href;
		} else {	// not "href", look for "src".
			attribute = "src";
			url = elmnt.getAttribute(attribute);
			if (url) {
				preURL = preURL_src;
			} else {
				console.log("---- Did not find 'href' or 'src' for " + elmnt);
				continue;
			}
		}

		// See if the url starts with documentation_URL_full which is the root of the documentation.
		var isDocumentation = (url.indexOf(documentation_URL_full) === 0) ? true : false;
		if (debug) if (! isDocumentation) console.log("isDoc=" + isDocumentation + ", url=" + url);
		if (! isDocumentation) {
			// Get the directory of the current page.
			var dir = document.URL.substr(0,document.URL.lastIndexOf('/'))
			var d = dir.lastIndexOf('/');
			dir = dir.substr(d+1);
			if (debug) console.log("== dir=" + dir);

			// Prepend the documentation string followed by the current directory
			// if we're not already in the documentation directory.
			if (url.substr(0,2) === "//") {
				// Why does htmlpreview start the URL with "//" ?
				url = "https:" + url;
			} else if (dir !== documentation_URL) {
				url = documentation_URL_full + dir + "/" + url;
			}
			if (debug) console.log("== new url=" + url);
		}

		// Only prepend on GitHub if not already there.
		if (onGitHub && url.indexOf(preURL) < 0) {
			url = preURL + url;
			if (debug) console.log("== new url after adding preURL=" + url);
		} else if (debug) console.log("url.indexOf(preURL)=" + url.indexOf(preURL));
		// else on Pi so nothing to do since the URL is already correct.

		elmnt[attribute] = url;
	}
}


// Include a file (e.g., header, footer, sidebar) in a page using Javascript.
function includeHTML(numCalls) {
	var t = typeof(numCalls)
	if (t == "undefined") numCalls = 1;
	if (debug) console.log("t=" + t + ", numCalls=" + numCalls);

	/* Search all HTML elements looking for ones that specify a file should be included. */
	allTags = document.getElementsByTagName("*");
	for (var i = 0; i < allTags.length; i++) {
		var elmnt = allTags[i];
		/*search for elements with a certain atrribute:*/
		var file = elmnt.getAttribute("w3-include-html");
		if (file) {
			/* Make an HTTP request using the attribute value as the file name */
			var xhttp = new XMLHttpRequest();
			xhttp.onreadystatechange = function() {
				if (this.readyState == 4) {
					if (this.status == 200)
						elmnt.innerHTML = this.responseText;
					else if (this.status == 400 || this.status == 404)
						elmnt.innerHTML = this.status + ": Page not found.";
					/*
						Remove the attribute, and call this function once more
						to see if there are any new entries to process and to handle
						any other original entries.
					 */
					elmnt.removeAttribute("w3-include-html");
					includeHTML(numCalls + 1);
				}
			}

			if (onGitHub) {
				var pre = preURL_include;
				if (file.substr(0,1) !== "/") {
					pre += "/";
				}
				file = pre + file;
			}  // else on Pi so nothing to do since the URL is already correct.
			xhttp.open("GET", file, true);
			xhttp.send();

			/* Exit the function: */
			return;
		}
	}

	// We only get here after a call to includeHTML() doesn't find any more files to include.
if (debug) console.log("AT END numCalls=" + numCalls);
	convertURL();
}
