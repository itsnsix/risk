export default class Helpers {
	static hexToRgb(hex) {
		let result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
		return result ? {
			r: parseInt(result[1], 16),
			g: parseInt(result[2], 16),
			b: parseInt(result[3], 16)
		} : null;
	}

	static matchPixelColor(imageData, pixelPosition, color, fillColor) {
		let r = imageData.data[pixelPosition];
		let g = imageData.data[pixelPosition + 1];
		let b = imageData.data[pixelPosition + 2];

		// Avoid going in a circle when encountering objects in the middle of fill area.
		if (r === fillColor.r && g === fillColor.g && b === fillColor.b) {
			return false;
		}

		return (r === color.r && g === color.g && b === color.b);
	}

	static colorPixel(imageData, pixelPosition, color) {
		imageData.data[pixelPosition] = color.r;
		imageData.data[pixelPosition + 1] = color.g;
		imageData.data[pixelPosition + 2] = color.b;
		imageData.data[pixelPosition + 3] = 255;
	}

	static validateInitialPixel(imageData, width, x, y) {
		let firstPixel = (y * width + x) * 4;

		let initialColorString = imageData.data[firstPixel] + ','
			+ imageData.data[firstPixel + 1]
			+ ',' + imageData.data[firstPixel + 2];

		if (initialColorString === '24,66,76') {
			console.log('Fill target is water.');
			return false;
		} else if (initialColorString === '153,217,234') {
			console.log('Fill target is a transport line.');
			return false;
		} else if (initialColorString === '0,0,0') {
			console.log('Fill target is a border.'); // Could also be map not finished loading.
			return false;
		}

		return true;
	}

	static formatDate(dateString) {
		if (!dateString || dateString.length !== 19) return;

		let year = dateString.substring(0, 4);
		let month = dateString.substring(5, 7);
		let day = dateString.substring(8, 10);
		let timestamp = dateString.substring(11, 16);

		return day + '/' + month + '/' + year + ' ' + timestamp;
	}
}