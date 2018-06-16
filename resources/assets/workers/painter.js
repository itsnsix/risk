import Helpers from '../js/helpers';

self.addEventListener('message', function(e) {
    let imageData = e.data.imageData;
    let territories = e.data.territories;
    let dimensions = e.data.dimensions;

    for (let key in territories) {
        if (territories.hasOwnProperty(key)) {
            let t = territories[key];

            if (t.occupation) {
                fillTerritory(imageData, dimensions, {x: t.x, y: t.y}, t.occupation.user.color);
            }
        }
    }

    self.postMessage(imageData);
}, false);

function fillTerritory(imageData, dimensions, location, hexColor) {
    let color = Helpers.hexToRgb(hexColor);

    if (!color) {
        console.log('Invalid fill color: ' + hexColor);
        return false;
    }

    let coloredPixels = 0;
    let pixelStack = [[location.x, location.y]];

    if (!Helpers.validateInitialPixel(imageData, dimensions.width,
        pixelStack[0][0], pixelStack[0][1])) {
        return false;
    }

    while (pixelStack.length) {
        // Where we current are in the image
        let currentPosition = pixelStack.pop();
        let x = currentPosition[0];
        let y = currentPosition[1];
        let pixelPosition = (y * dimensions.width + x) * 4;
        let matchingColor = {
            r: imageData.data[pixelPosition],
            g: imageData.data[pixelPosition + 1],
            b: imageData.data[pixelPosition + 2]
        };

        // Walk upwards from position until we hit another color than the one we clicked on
        while (y-- >= 0 && Helpers.matchPixelColor(imageData, pixelPosition, matchingColor, color)) {
            pixelPosition -= dimensions.width * 4;
        }

        pixelPosition += dimensions.width * 4;
        ++y;
        let reachLeft = false, reachRight = false;
        // Walk downwards and look for pixels of the same color to the left and right
        while (y++ < dimensions.height - 1 && Helpers.matchPixelColor(imageData, pixelPosition, matchingColor, color)) {
            Helpers.colorPixel(imageData, pixelPosition, color);
            coloredPixels++;

            // Check left
            if (x > 0) {
                if (Helpers.matchPixelColor(imageData, pixelPosition - 4, matchingColor, color)) {
                    if (!reachLeft) {
                        pixelStack.push([x - 1, y]);
                        reachLeft = true;
                    }
                } else if (reachLeft) {
                    reachLeft = false;
                }
            }

            // Check right
            if (x < dimensions.width - 1) {
                if (Helpers.matchPixelColor(imageData, pixelPosition + 4, matchingColor, color)) {
                    if (!reachRight) {
                        pixelStack.push([x + 1, y]);
                        reachRight = true;
                    }
                } else if (reachRight) {
                    reachRight = false;
                }
            }

            pixelPosition += dimensions.width * 4;
        }

        if (coloredPixels > 50000) {
            console.log('Too big area');
            return false;
        }
    }
}